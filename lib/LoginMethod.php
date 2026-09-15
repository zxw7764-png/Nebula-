<?php
/**
 * 登录方式配置
 * ------------------------------------------------------------------
 * 设计原则（与「一个接口兼容多种字段、服务端猜」相反）：
 *   后台「系统设置 → 登录方式」**单选**决定当前唯一生效的登录方式，
 *   init 把它下发给客户端，客户端**直接按该方式对应的字段发请求**，
 *   不给客户端塞一堆字段让服务端推断。
 *
 * 三种方式互斥：
 *   password       用户名 + 密码
 *   username_code  用户名 + 激活码
 *   code           激活码（卡密直登）
 *
 * 其中两种「激活码」方式内部的卡密语义是统一的：
 *   卡密已绑定账号 -> 直接登录该账号
 *   卡密未绑定     -> 自动建号并激活（username_code 用用户填的用户名，
 *                    code 方式自动生成用户名）
 * 但未绑定卡密**不会**被挂到已存在的他人账号上（防账号接管）。
 */
class LoginMethod
{
    const PASSWORD      = 'password';      // 用户名 + 密码
    const USERNAME_CODE = 'username_code'; // 用户名 + 激活码
    const CODE          = 'code';          // 激活码（卡密直登）

    /** 全部可选方式：取值 => 展示名 */
    public static function all(): array
    {
        return [
            self::PASSWORD      => '用户名 + 密码',
            self::USERNAME_CODE => '用户名 + 激活码',
            self::CODE          => '激活码（卡密直登）',
        ];
    }

    /** 当前生效方式（后台设置优先，回落 config.php，非法值兜底为 password） */
    public static function current(): string
    {
        $m = (string) (Setting::get('login_methods')
            ?: Config::get('policy.login_methods', self::PASSWORD));

        return isset(self::all()[$m]) ? $m : self::PASSWORD;
    }

    /**
     * 指定软件的生效方式：分软件设置优先（软件管理弹窗可单独配置），
     * 软件值为空 / 非法 / 老库无该列时回落全局 current()。
     */
    public static function currentFor(?array $sw): string
    {
        if ($sw) {
            $m = trim((string) ($sw['login_methods'] ?? ''));
            if ($m !== '' && isset(self::all()[$m])) {
                return $m;
            }
        }
        return self::current();
    }

    /** 指定软件下发给客户端 / 官网展示用的规格（分软件登录方式） */
    public static function specFor(?array $sw): array
    {
        return self::spec(self::currentFor($sw));
    }

    public static function is(string $method): bool
    {
        return self::current() === $method;
    }

    /** 是否属于「激活码」体系（含用户名+激活码、纯激活码） */
    public static function isCard(string $method = ''): bool
    {
        $m = $method !== '' ? $method : self::current();
        return $m === self::USERNAME_CODE || $m === self::CODE;
    }

    public static function label(string $method = ''): string
    {
        $m = $method !== '' ? $method : self::current();
        return self::all()[$m] ?? '未知';
    }

    /** 当前方式客户端必须提交的字段（顺序即表单展示顺序） */
    public static function fields(string $method = ''): array
    {
        $m = $method !== '' && isset(self::all()[$method]) ? $method : self::current();

        switch ($m) {
            case self::USERNAME_CODE:
                return ['username', 'code'];
            case self::CODE:
                return ['code'];
            default:
                return ['username', 'password'];
        }
    }

    /**
     * 下发给客户端 / 官网展示用的规格。
     * 客户端据此决定登录界面渲染哪些输入框、以及提交哪些字段。
     */
    public static function spec(?string $method = null): array
    {
        $m = ($method !== null && isset(self::all()[$method])) ? $method : self::current();

        return [
            'method'        => $m,
            'label'         => self::all()[$m],
            'need_username' => in_array($m, [self::PASSWORD, self::USERNAME_CODE], true),
            'need_password' => $m === self::PASSWORD,
            'need_code'     => self::isCard($m),
            'fields'        => self::fields($m),
        ];
    }
}
