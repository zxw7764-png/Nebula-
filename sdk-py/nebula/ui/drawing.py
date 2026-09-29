# -*- coding: utf-8 -*-
"""
Nebula UI · 绘制工具
------------------------------------------------------------------------------
  · 圆角矩形（pygame 内置 border_radius）
  · 垂直渐变背景 + 左侧霓虹竖线 + 右下装饰圆（overlay 缓存）
  · 文本绘制（DrawText / DrawCenteredText）
  · 自绘标题栏（顶部 4px 霓虹条 / 1px 分割线 / 最小化与关闭按钮）
"""
from __future__ import annotations

import pygame

from . import theme


# ── 字体（候选链：微软雅黑 → 黑体 → Arial）──────────────────────────────────
_font_cache: dict = {}
_font_path: str = ""


def _find_font_path() -> str:
    candidates = [
        "C:/Windows/Fonts/msyh.ttc",
        "C:/Windows/Fonts/simhei.ttf",
        "C:/Windows/Fonts/arial.ttf",
    ]
    for p in candidates:
        try:
            with open(p, "rb"):
                return p
        except OSError:
            continue
    import glob
    for p in glob.glob("C:/Windows/Fonts/*.ttf"):
        return p
    return ""


def font(size: int) -> pygame.font.Font:
    """字体单例（按字号缓存）。"""
    global _font_path
    if size not in _font_cache:
        if not _font_path:
            _font_path = _find_font_path()
        _font_cache[size] = (
            pygame.font.Font(_font_path, size) if _font_path else pygame.font.SysFont("microsoftyahei", size)
        )
    return _font_cache[size]


# ── 圆角矩形 ─────────────────────────────────────────────────────────────────

def rounded_rect(target: pygame.Surface, rect: pygame.Rect, radius: int, color) -> None:
    """圆角矩形（pygame 内置 border_radius 实现）。"""
    radius = max(0, min(radius, min(rect.width, rect.height) // 2))
    if rect.width <= 0 or rect.height <= 0:
        return
    pygame.draw.rect(target, color, rect, border_radius=radius)


# ── 渐变背景 + 装饰（按尺寸缓存）────────────────────────────────────────────
_bg_cache: dict = {}

def _build_bg(w: int, h: int) -> pygame.Surface:
    """垂直渐变 kBgTop→kBgBottom + 左侧霓虹竖线(透明渐显) + 右下双装饰圆。"""
    surf = pygame.Surface((w, h))
    top, bottom = theme.kBgTop, theme.kBgBottom
    for y in range(h):
        t = y / max(1, h - 1)
        c = tuple(int(top[i] + (bottom[i] - top[i]) * t) for i in range(3))
        pygame.draw.line(surf, c, (0, y), (w, y))

    # 装饰需要 alpha 混合 → 画到 SRCALPHA overlay 再叠加
    overlay = pygame.Surface((w, h), pygame.SRCALPHA)

    # 左侧装饰竖线：y 60 → h-90，alpha 0 → 255 渐显
    y0, y1 = 60.0, h - 90
    steps = max(8, int(y1 - y0))
    for i in range(steps):
        a = int(255 * i / steps)
        ya = y0 + (y1 - y0) * i / steps
        yb = y0 + (y1 - y0) * (i + 1) / steps
        pygame.draw.line(overlay, (0, 229, 160, a), (1.5, ya), (1.5, yb))

    # 右下角装饰圆（圆心 = 左上角坐标 + 半径）
    c1 = pygame.Surface((92, 92), pygame.SRCALPHA)
    pygame.draw.circle(c1, (0, 229, 160, 18), (46, 46), 46)
    overlay.blit(c1, (w - 128, h - 128))

    c2 = pygame.Surface((48, 48), pygame.SRCALPHA)
    pygame.draw.circle(c2, (0, 229, 160, 30), (24, 24), 24)
    overlay.blit(c2, (w - 68, h - 68))

    surf.blit(overlay, (0, 0))
    return surf


def draw_background(target: pygame.Surface) -> None:
    """渐变背景 + 装饰。"""
    w, h = target.get_size()
    if (w, h) not in _bg_cache:
        _bg_cache[(w, h)] = _build_bg(w, h)
    target.blit(_bg_cache[(w, h)], (0, 0))


# ── 文本 ─────────────────────────────────────────────────────────────────────

def _render(text: str, size: int, color, bold: bool) -> pygame.Surface:
    f = font(size)
    if bold:
        f.set_bold(True)
        t = f.render(text, True, color)
        f.set_bold(False)
        return t
    return f.render(text, True, color)


def draw_text(target: pygame.Surface, text, x: float, y: float,
              size: int, color, bold: bool = False) -> None:
    """左上角定位绘制文本。"""
    target.blit(_render(str(text), size, color, bold), (x, y))


def draw_centered_text(target: pygame.Surface, text, area: pygame.Rect,
                       size: int, color, bold: bool = False) -> None:
    """区域内居中绘制文本。"""
    t = _render(str(text), size, color, bold)
    r = t.get_rect(center=area.center)
    target.blit(t, r)


# ── 标题栏 ──────────────────────────────────────────────────────────────────

def close_btn_rect(window_width: int) -> pygame.Rect:
    return pygame.Rect(window_width - 46, 6, 36, 26)


def min_btn_rect(window_width: int) -> pygame.Rect:
    return pygame.Rect(window_width - 88, 6, 36, 26)


def draw_title_bar(target: pygame.Surface, title: str,
                   hover_min: bool = False, hover_close: bool = False,
                   min_enabled: bool = True) -> None:
    """自绘标题栏：背景条 + 顶部霓虹条 + 分割线 + 标题 + 最小化/关闭按钮。"""
    w = target.get_width()

    # 背景条
    pygame.draw.rect(target, theme.kTitleBarBg, (0, 0, w, theme.kTitleBarHeight))
    # 顶部霓虹绿装饰条
    rounded_rect(target, pygame.Rect(0, 0, w, 4), 2, theme.kAccent)
    # 底部 1px 分割线
    pygame.draw.rect(target, theme.kInputBorder, (0, theme.kTitleBarHeight - 1, w, 1))

    # 标题文字（垂直居中，x=14）
    t = font(13).render(title, True, theme.kMuted)
    target.blit(t, (14, (theme.kTitleBarHeight - t.get_height()) // 2))

    # 最小化按钮（− U+2212）
    min_btn = min_btn_rect(w)
    if min_enabled:
        if hover_min:
            rounded_rect(target, min_btn, 5, theme.kButtonHover)
        draw_centered_text(target, "\u2212", min_btn, 15,
                           theme.kText if hover_min else theme.kMuted)

    # 关闭按钮（× U+00D7）
    close_btn = close_btn_rect(w)
    if hover_close:
        rounded_rect(target, close_btn, 5, theme.kCloseHover)
    draw_centered_text(target, "\u00d7", close_btn, 15,
                       (255, 255, 255) if hover_close else theme.kMuted)


# ── 窗口工具（无边框拖拽 / 最小化 / 居中）───────────────────────────────────

def get_hwnd() -> int:
    """当前窗口句柄（Windows HWND）。"""
    try:
        return pygame.display.get_wm_info().get("window", 0) or 0
    except Exception:
        return 0


def begin_system_drag() -> None:
    """系统级窗口拖拽：WM_NCLBUTTONDOWN + HTCAPTION，Windows 接管直到鼠标松开。"""
    hwnd = get_hwnd()
    if not hwnd:
        return
    import ctypes
    user32 = ctypes.windll.user32
    user32.ReleaseCapture()
    user32.SendMessageW(hwnd, 0x00A1, 0x0002, 0)   # WM_NCLBUTTONDOWN, HTCAPTION


def minimize_window() -> None:
    """最小化窗口。"""
    hwnd = get_hwnd()
    if hwnd:
        import ctypes
        ctypes.windll.user32.ShowWindow(hwnd, 6)   # SW_MINIMIZE
        return
    try:
        pygame.display.iconify()
    except Exception:
        pass


def center_on_screen(width: int, height: int) -> None:
    """窗口居中。"""
    hwnd = get_hwnd()
    if hwnd:
        try:
            import ctypes
            info = pygame.display.Info()
            ctypes.windll.user32.SetWindowPos(
                hwnd, 0, (info.current_w - width) // 2, (info.current_h - height) // 2,
                0, 0, 0x0001 | 0x0004)             # SWP_NOSIZE | SWP_NOZORDER
            return
        except Exception:
            pass
    try:
        from pygame._sdl2.video import Window
        info = pygame.display.Info()
        Window.from_display_module().set_position(
            ((info.current_w - width) // 2, (info.current_h - height) // 2))
    except Exception:
        pass
