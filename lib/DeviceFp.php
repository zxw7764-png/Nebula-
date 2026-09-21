<?php
/**
 * 设备指纹：多硬件组件哈希 + 稳定性加权 + 模拟器/虚拟机识别
 * ------------------------------------------------------------------
 * 客户端可选上报 device_fp（组件名 => 该硬件的哈希/特征串）：
 *
 *   "device_fp": {
 *     "board": "9f2c…",   // 主板
 *     "cpu":   "1a7b…",   // CPU
 *     "disk":  "c3d4…",   // 系统盘序列号
 *     "bios":  "55ee…",   // BIOS/UEFI
 *     "mac":   "001122334455", // 主网卡（直接给 MAC 时可用于虚拟机识别）
 *     "gpu":   "77aa…"    // 显卡
 *   }
 *
 * 服务端按组件「稳定性」加权合成一个加权指纹 fp_hash，用于：
 *   1) 容忍部分组件漂移 —— 换网卡、插拔外设、驱动升级不会误判为换机；
 *   2) 识别机器码伪造 —— 同一 machine_id 但加权指纹大幅变化；
 *   3) 识别模拟器 / 虚拟机 —— 名字特征、组件熵、网卡 OUI 三重信号。
 *
 * 设计要点：客户端不上报时本类完全不参与判定，行为与旧版本逐字节一致，
 * 因此所有对接方都可以按自己的节奏升级，不会因为服务端升级而掉线。
 */
class DeviceFp
{
    /**
     * 组件权重：越稳定、越难伪造的组件权重越高。
     * 权重同时用于「加权相似度」计算，总和不必是 100。
     */
    public const WEIGHTS = [
        'board' => 30, // 主板：更换主板基本等于换机
        'cpu'   => 25, // CPU
        'disk'  => 20, // 系统盘
        'bios'  => 15, // BIOS/UEFI
        'mac'   => 10, // 网卡：换网卡/虚拟网卡最常见，权重最低
        'gpu'   => 10, // 显卡
    ];

    /** 组件值最大长度（规范化后） */
    private const VAL_LEN = 96;

    /**
     * 模拟器 / 虚拟机特征关键词（对 device_name + os_info 做小写包含匹配）
     * 覆盖主流虚拟化平台与安卓模拟器。
     */
    public const VM_KEYWORDS = [
        // 桌面虚拟化
        'vmware', 'virtualbox', 'virtual machine', 'virtualbox',
        'qemu', 'kvm', 'xen', 'hyper-v', 'hyperv', 'parallels',
        'bochs', 'bhyve', 'vmware player', 'vbox',
        // 安卓模拟器 / 云手机
        'bluestacks', 'nox', 'noxplayer', 'ldplayer', 'memu',
        'mumu', 'gameloop', 'genymotion', 'waydroid', 'android emulator',
        '雷电模拟器', '夜神', '逍遥模拟器', '天天模拟器', '腾讯手游助手',
        '云手机', 'windows sandbox', 'sandboxie',
        // 兜底：显式声明为虚拟机
        'virtual',
    ];

    /**
     * 常见虚拟机网卡 OUI（MAC 前 6 位十六进制，小写）。
     * 仅在客户端直接上报了 12 位裸 MAC 时生效（哈希值长度不为 12，天然不会误判）。
     */
    public const VM_OUI = [
        '000569', // VMware
        '000c29', // VMware
        '001c14', // VMware
        '005056', // VMware
        '080027', // VirtualBox
        '00155d', // Hyper-V
        '525400', // QEMU / KVM
        '00163e', // Xen
        '0a0027', // VirtualBox (新版)
    ];

    /** 是否启用（总开关；分软件策略覆盖：当前软件 policy_json.device_fp_enable 优先） */
    public static function enabled(): bool
    {
        if (class_exists('Software')) {
            $v = Policy::swPolicyVal(Software::current(), 'device_fp_enable');
            if ($v !== null) {
                return (bool) $v;
            }
        }
        return (bool) Config::get('device_fp.enable', true);
    }

    /** 客户端本次是否上报了指纹组件 */
    public static function hasPayload(array $info): bool
    {
        return isset($info['device_fp']) && is_array($info['device_fp']) && $info['device_fp'] !== [];
    }

    /**
     * 从请求数据中取出指纹组件
     * 兼容两种传法：直接给对象 {"board":"…"} 或给 JSON 字符串。
     * @return array 规范化后的组件（无有效组件时为空数组）
     */
    public static function fromRequest(array $src): array
    {
        $raw = $src['device_fp'] ?? null;

        if (is_string($raw)) {
            $raw = $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($raw)) {
                $raw = [];
            }
        } elseif (is_object($raw)) {
            $raw = (array) $raw;
        }

        return self::normalize($raw);
    }

    /**
     * 规范化组件：只保留权重表内的键，去空、限长、只留 [a-z0-9]。
     * 统一小写并剔除分隔符，使 "AA:BB:CC" 与 "aabbcc" 归一（也便于 OUI 匹配）。
     */
    public static function normalize($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_keys(self::WEIGHTS) as $key) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }
            $v = $raw[$key];
            if ($v === null || is_array($v) || is_object($v)) {
                continue;
            }
            $v = strtolower(trim((string) $v));
            $v = (string) preg_replace('/[^a-z0-9]/', '', $v);
            if ($v === '') {
                continue;
            }
            $out[$key] = substr($v, 0, self::VAL_LEN);
        }
        return $out;
    }

    /**
     * 合成加权指纹
     * @return array{hash:?string, score:int, count:int, components:array}
     */
    public static function compose($raw): array
    {
        $c = self::normalize($raw);
        if (!$c) {
            return ['hash' => null, 'score' => 0, 'count' => 0, 'components' => []];
        }

        $score = 0;
        $parts = [];
        foreach ($c as $k => $v) {
            $score += self::WEIGHTS[$k] ?? 0;
            $parts[] = $k . '=' . $v;
        }
        // 排序后拼接：指纹与上报顺序无关，同一台机器必须得到同一个 hash
        sort($parts);

        return [
            'hash'       => hash('sha256', 'nbfp|' . implode('|', $parts)),
            'score'      => $score,
            'count'      => count($c),
            'components' => $c,
        ];
    }

    /**
     * 加权相似度（0-100）
     * ------------------------------------------------------------------
     * 只在「双方都上报了的组件」上比较：命中权重 / 共同权重。
     * 这样换网卡（mac 权重 10）不会拉低稳定组件（board/cpu/disk）的判定，
     * 而主板+CPU 同时变化（权重 55）就会被判为不同机器。
     */
    public static function similarity($a, $b): int
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        if (!$a || !$b) {
            return 0;
        }

        $commonW = 0;
        $matchW  = 0;
        foreach ($a as $k => $v) {
            if (!array_key_exists($k, $b)) {
                continue;
            }
            $w = self::WEIGHTS[$k] ?? 0;
            $commonW += $w;
            if (hash_equals($v, $b[$k])) {
                $matchW += $w;
            }
        }
        if ($commonW <= 0) {
            return 0;
        }
        return (int) round($matchW * 100 / $commonW);
    }

    /**
     * 漂移判定：新旧组件是否仍属同一台机器
     * ------------------------------------------------------------------
     * 单靠百分比阈值无法区分「换了主板」和「换了网卡+显卡+BIOS」——
     * 前者是换机、后者是正常升级，但两者相似度可能接近。因此采用两级规则：
     *
     *   1) 核心组件（board/cpu，可由 device_fp.core_components 配置）
     *      双方都上报时：只要有一个不一致 → 判定为不同机器（疑似伪造机器码）；
     *   2) 核心组件缺失时：回落到加权相似度阈值 drift_threshold。
     *
     * 换硬盘（SSD 升级）、换网卡、换显卡、刷 BIOS 都落在容忍范围内，
     * 不会因为一次正常升级就被踢下线。
     *
     * @return array{same:bool, similarity:int, core_changed:string[], changed:string[]}
     */
    public static function driftCheck($old, $new): array
    {
        $old = self::normalize($old);
        $new = self::normalize($new);
        $sim = self::similarity($old, $new);

        $core = (array) Config::get('device_fp.core_components', ['board', 'cpu']);

        $coreChanged = [];
        $coreCompared = 0;
        $changed = [];
        foreach ($new as $k => $v) {
            if (!array_key_exists($k, $old)) {
                continue;
            }
            if (!hash_equals($old[$k], $v)) {
                $changed[] = $k;
                if (in_array($k, $core, true)) {
                    $coreChanged[] = $k;
                }
            }
            if (in_array($k, $core, true)) {
                $coreCompared++;
            }
        }

        if ($coreCompared > 0) {
            $same = $coreChanged === [];
        } else {
            $same = $sim >= (int) Config::get('device_fp.drift_threshold', 60);
        }

        return [
            'same'         => $same,
            'similarity'   => $sim,
            'core_changed' => $coreChanged,
            'changed'      => $changed,
        ];
    }

    /**
     * 风险信号检测
     * @param array $components 规范化后的组件（也可传原始数组，内部会再规范化）
     * @param array $info       含 device_name / os_info
     * @return string[] 标记列表：vm / low_entropy / same_value
     */
    public static function riskFlags($components, array $info = []): array
    {
        $flags = [];
        $c     = self::normalize($components);

        // 1) 组件过少：真实机器一般能稳定取到 4 项以上
        $min = (int) Config::get('device_fp.min_components', 3);
        if ($min > 0 && $c !== [] && count($c) < $min) {
            $flags[] = 'low_entropy';
        }

        // 2) 多个组件值完全相同：多为批量化伪造/模拟器模板
        if (count($c) >= 2) {
            $uniq = array_unique(array_values($c));
            if (count($uniq) === 1) {
                $flags[] = 'same_value';
            }
        }

        // 3) 名称 / 系统信息命中虚拟机或模拟器关键词
        $text = strtolower(trim((string) ($info['device_name'] ?? '')) . ' '
            . trim((string) ($info['os_info'] ?? '')));
        if ($text !== '') {
            foreach (self::VM_KEYWORDS as $kw) {
                if ($kw !== '' && strpos($text, $kw) !== false) {
                    $flags[] = 'vm';
                    break;
                }
            }
        }

        // 4) 网卡 OUI 命中常见虚拟机厂商（仅裸 MAC 长度 12 时判定）
        $mac = $c['mac'] ?? '';
        if ($mac !== '' && strlen($mac) === 12) {
            foreach (self::VM_OUI as $oui) {
                if (substr($mac, 0, strlen($oui)) === $oui) {
                    $flags[] = 'vm';
                    break;
                }
            }
        }

        return array_values(array_unique($flags));
    }

    /** 是否疑似模拟器 / 虚拟机 */
    public static function isVirtual(array $flags): bool
    {
        return in_array('vm', $flags, true);
    }

    /** 风险标记的中文说明（后台展示用） */
    public static function flagLabel(string $flag): string
    {
        return [
            'vm'             => '疑似模拟器/虚拟机',
            'low_entropy'    => '硬件信息过少',
            'same_value'     => '组件值雷同',
            'fp_changed'     => '机器码疑似伪造',
            'multi_fp'       => '同账号多机器指纹',
            'shared_machine' => '同一机器多账号',
        ][$flag] ?? $flag;
    }

    /** 组件中文名（后台展示用） */
    public static function componentLabel(string $key): string
    {
        return [
            'board' => '主板',
            'cpu'   => 'CPU',
            'disk'  => '系统盘',
            'bios'  => 'BIOS',
            'mac'   => '网卡',
            'gpu'   => '显卡',
        ][$key] ?? $key;
    }

    /** 组件权重上限（组件齐全时的总分，用于展示「完整度 N/110」） */
    public static function maxScore(): int
    {
        return array_sum(self::WEIGHTS);
    }

    /**
     * 组件明细 → 后台可读结构（含中文名 / 权重 / 是否为虚拟网卡）
     * 保持权重表顺序输出，客户端少报的组件不占位，前端只渲染返回项。
     *
     * @param array $components 规范化后的组件（内部会再规范化一次）
     */
    public static function componentsView($components): array
    {
        $c = self::normalize($components);
        $out = [];
        foreach (array_keys(self::WEIGHTS) as $key) {
            if (!isset($c[$key])) {
                continue;
            }
            $v = $c[$key];
            $row = [
                'key'    => $key,
                'label'  => self::componentLabel($key),
                'value'  => $v,
                'weight' => self::WEIGHTS[$key],
            ];
            // 裸 MAC 才判定 OUI，哈希串长度不为 12 天然不会命中
            if ($key === 'mac' && strlen($v) === 12) {
                foreach (self::VM_OUI as $oui) {
                    if (substr($v, 0, strlen($oui)) === $oui) {
                        $row['vm_oui'] = true;
                        break;
                    }
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /** 把逗号分隔的标记转成可读文本 */
    public static function flagsText(?string $flags): string
    {
        $flags = trim((string) $flags);
        if ($flags === '') {
            return '';
        }
        $out = [];
        foreach (explode(',', $flags) as $f) {
            $f = trim($f);
            if ($f !== '') {
                $out[] = self::flagLabel($f);
            }
        }
        return implode('、', $out);
    }
}
