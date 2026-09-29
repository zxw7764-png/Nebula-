# -*- coding: utf-8 -*-
"""headless UI 测试：dummy 显存驱动下真跑 Run() 主循环（init 线程真连本地服务端）。"""
import os
import sys
os.environ["SDL_VIDEODRIVER"] = "dummy"
sys.path.insert(0, r"D:/phpstudy_pro/WWW/login/nebula-py")

import pygame

from nebula.ui.login_window import LoginWindow

# 第 8 帧注入 QUIT，让 Run() 自然走完整循环后退出
frame = {"n": 0}
orig_get = pygame.event.get
def fake_get():
    frame["n"] += 1
    if frame["n"] > 8:
        return [pygame.event.Event(pygame.QUIT)]
    return orig_get()
pygame.event.get = fake_get

lw = LoginWindow()
ok = lw.Run()

print(f"Run returned: {ok} (QUIT 注入应为 False)")
print(f"init_ok: {lw.init_ok}")
print(f"status: {lw.status!r}")
print(f"notice_text: {lw.notice_text!r}")
print(f"user_label/secret_label: {lw.user_label!r}/{lw.secret_label!r}")
print(f"login_spec method: {lw.init_result.login_spec.method if lw.init_result else 'N/A'}")
assert lw.init_ok, "init 应在后台线程成功"
assert lw.user_label == "用户名" and lw.secret_label == "密码", "password 方式标签联动错误"
print("HEADLESS_UI_OK")
