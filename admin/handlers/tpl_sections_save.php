<?php
/**
 * admin action: tpl_sections_save
 * 模板布局与自定义区块保存（side=web 官网 / side=shop 发卡网，web/Template 文件夹型模板）：
 *   mode=layout   保存区块顺序 → 写回该端入口 css 头注释 Layout: 行（官网 web.css / 发卡网 shop.css；
 *                 留空数组 = 清除声明，用内置默认）
 *   mode=section  保存/新建自定义区块 → 官网写 sections/<id>.html，发卡网写 shop-sections/<id>.html
 * 文件直接落在模板开发目录，前台改完即生效（与手工改文件同一路径）。
 * 安全：tpl 经 UiTemplate 注册校验、区块 id 白名单正则、路径全部由常量拼接，
 *       无用户输入进入文件路径，杜绝穿越；内容限 64KB。
 * 权限：settings.site（官网 / 发卡网外观类）。
 */

AdminPermission::require($admin, AdminPermission::SETTINGS_SITE);

$side = Util::str($input, 'side', 'web') === 'shop' ? 'shop' : 'web';
$sideName = $side === 'shop' ? '发卡网' : '官网';
$tpl = strtolower(trim(Util::str($input, 'template', '')));
if ($tpl === '' || !UiTemplate::valid($side, $tpl)) {
    Response::error(1001, '模板不存在或未注册');
}
$t = UiTemplate::detect($side)[$tpl] ?? null;
if (!$t || $t['src'] !== 'dev') {
    Response::error(1001, '仅支持 web/Template 文件夹型模板');
}
$base = UiTemplate::devDir() . '/' . $tpl;
if (!is_dir($base)) {
    Response::error(1004, '模板目录不存在');
}
$mode = Util::str($input, 'mode', '');

// ---------------- 保存布局顺序：写回 css 头注释 Layout: 行 ----------------
if ($mode === 'layout') {
    $idsIn = Util::get($input, 'layout', []);
    if (!is_array($idsIn)) {
        Response::error(1001, 'layout 需为数组');
    }
    // 规范化：兼容中文输入法的全角逗号 / 顿号 / 分号（如「hero，banner」整串传入也能切开）
    $ids     = [];
    $dropped = [];   // 无效项显式带回，避免「提示成功实际没保存」的假象
    foreach ($idsIn as $v) {
        foreach (preg_split('/[,，、;；\s]+/u', str_replace(["，", "、", ";", "；"], ",", (string) $v)) as $t0) {
            $t0 = strtolower(trim((string) $t0));
            if ($t0 === '') {
                continue;
            }
            if (preg_match('/^[a-z0-9_]{1,32}$/', $t0) && !in_array($t0, $ids, true)) {
                $ids[] = $t0;
                if (count($ids) >= 24) {
                    break 2;      // 与 parseCssMeta 同口径
                }
            } elseif (!in_array($t0, $dropped, true)) {
                $dropped[] = $t0;
            }
        }
    }

    if (!UiTemplate::writeLayoutLine($base . '/' . $t['css'], $ids)) {
        Response::error(5000, '写入模板 css 失败');
    }
    Audit::log($admin, 'tpl_sections_save', $side . '/' . $tpl, $sideName . '模板布局顺序：' . ($ids ? implode(', ', $ids) : '清除声明（内置默认）'));
    $msg = $ids ? '布局顺序已保存，前台即时生效' : '已清除布局声明，恢复内置默认顺序';
    if ($dropped) {
        $msg .= '；已忽略无效项：' . implode('、', array_slice($dropped, 0, 5)) . '（仅限小写字母/数字/下划线）';
    }
    Response::ok(['layout' => $ids, 'dropped' => $dropped], $msg);
}

// ---------------- 保存 / 新建 / 删除自定义区块文件 ----------------
if ($mode === 'section') {
    $id = strtolower(trim(Util::str($input, 'id', '')));
    if (!preg_match('/^[a-z0-9_]{1,32}$/', $id)) {
        Response::error(1001, '区块 id 仅限小写字母 / 数字 / 下划线，1-32 位');
    }
    if (in_array($id, $side === 'shop' ? UiTemplate::BUILTIN_SHOP_SECTIONS : UiTemplate::BUILTIN_SECTIONS, true)) {
        Response::error(1001, '该 id 是内置区块，内容请在后台对应内容页配置');
    }
    $dir  = $base . ($side === 'shop' ? '/shop-sections' : '/sections');
    $file = $dir . '/' . $id . '.html';

    // 删除区块：删文件 + 自动从布局声明里移除该 id（区块没了声明还留着会导致一直占位）
    if (Util::get($input, 'delete')) {
        if (!is_file($file)) {
            Response::error(1004, '区块文件不存在');
        }
        $declared = UiTemplate::layout($side, $tpl);
        if ($declared && in_array($id, $declared, true)) {
            $rest = array_values(array_diff($declared, [$id]));
            if (!UiTemplate::writeLayoutLine($base . '/' . $t['css'], $rest)) {
                Response::error(5000, '从布局声明移除该区块失败，未删除文件');
            }
        }
        if (!@unlink($file)) {
            Response::error(5000, '删除区块文件失败');
        }
        Audit::log($admin, 'tpl_sections_save', $side . '/' . $tpl . '/' . $id, '删除' . $sideName . '模板自定义区块（布局声明同步移除）');
        Response::ok(['id' => $id], '自定义区块已删除（布局声明同步移除）');
    }

    $content = Util::str($input, 'content', '');
    if (strlen($content) > 64 * 1024) {
        Response::error(1001, '区块内容需在 64KB 以内');
    }
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        Response::error(5000, '创建区块目录失败');
    }
    $isNew = !is_file($file);
    if (@file_put_contents($file, $content) === false) {
        Response::error(5000, '写入区块文件失败');
    }
    Audit::log($admin, 'tpl_sections_save', $side . '/' . $tpl . '/' . $id, ($isNew ? '新建' : '保存') . $sideName . '模板自定义区块');
    Response::ok(['id' => $id], ($isNew ? '自定义区块已创建' : '自定义区块已保存') . '；需加入布局顺序后前台显示');
}

Response::error(1001, '未知 mode');
