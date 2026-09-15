#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Nebula 网络验证 - Python 客户端 SDK

用途：
  1. 直接作为客户端使用（Python 项目）
  2. 作为协议参考实现，对照编写 C++ / C# 客户端
  3. 接口联调测试

依赖: pip install requests pycryptodome

协议：AES-256-CBC 加密 + HMAC-SHA256 签名（encrypt-then-MAC）：
  · 请求：先对密文做 HMAC 签名，服务端验签通过才解密
  · 响应：服务端用同一把盐签回，客户端同样先验签再解密
  · 会话级签名密钥：init 返回 session.{k,s}，业务接口必须带 k

调用顺序必须是 init() -> 其它接口，因为 init 负责下发会话密钥
（缺了它，除公开接口外的请求都会被服务端以 5002 拒绝）。
"""

import base64
import hashlib
import hmac
import json
import os
import time
from typing import Any, Optional

import requests
from Crypto.Cipher import AES
from Crypto.Util.Padding import pad, unpad


class NebulaError(Exception):
    """业务异常，携带业务码"""
    def __init__(self, code: int, msg: str, data: Any = None):
        super().__init__(f"[{code}] {msg}")
        self.code = code
        self.msg = msg
        self.data = data


class NebulaClient:
    """
    Nebula 验证客户端

    >>> cli = NebulaClient("http://127.0.0.1", "你的AES_KEY", "你的SIGN_SALT")
    >>> cli.init("1.0.0")
    >>> cli.login("testuser", "123456")
    >>> cli.heartbeat()
    """

    def __init__(
        self,
        base_url: str,
        aes_key: str,
        sign_salt: str,
        machine_id: Optional[str] = None,
        timeout: int = 15,
        verify_ssl: bool = True,
    ):
        self.base_url = base_url.rstrip('/')
        self.aes_key = aes_key
        self.sign_salt = sign_salt
        self.timeout = timeout
        self.verify_ssl = verify_ssl

        # 会话级签名密钥（init 下发）。
        # 服务端默认 security.session_key_required=true：
        # 除了 init/notice/version/online 四个公开接口，
        # 其余请求都必须带信封字段 k，且签名用会话盐 s 而非主盐。
        # 少了这两个值，业务接口会一律返回 5002。
        self.session_kid: str = ''
        self.session_skey: str = ''

        self.token: str = ''
        self.machine_id: str = machine_id or self._gen_machine_id()
        self.client_ver: str = '1.0.0'
        self.user: dict = {}

    # ------------------------------------------------------------------
    # 加密层
    # ------------------------------------------------------------------
    def _key(self) -> bytes:
        return hashlib.sha256(self.aes_key.encode()).digest()[:32]

    def _iv(self) -> bytes:
        """v1/CBC 用的固定 IV（由密钥派生）。"""
        return hashlib.md5(self.aes_key.encode()).digest()[:16]

    def _encrypt(self, plain: str) -> str:
        """AES-256-CBC: base64(iv[16] + ciphertext)"""
        raw = plain.encode('utf-8')
        cipher = AES.new(self._key(), AES.MODE_CBC, self._iv())
        ct = cipher.encrypt(pad(raw, AES.block_size))
        return base64.b64encode(self._iv() + ct).decode()

    def _decrypt(self, b64: str) -> str:
        """解密 base64(iv[16] + ciphertext)"""
        raw = base64.b64decode(b64)
        c = AES.new(self._key(), AES.MODE_CBC, raw[:16])
        return unpad(c.decrypt(raw[16:]), AES.block_size).decode('utf-8')

    def _sign(self, data: str, t: int, n: str) -> str:
        """
        计算信封签名。

        ⚠️ 盐的取法与 SDK 的 CurrentSalt() 完全一致：
         **有会话盐就用会话盐，没有才用主盐**。
        服务端 parseRequest 会用「验请求所用的同一把盐」来给响应签名，
        因此客户端验响应签名时也必须用同一把盐，否则会出现
        「请求通过、响应验签失败」的诡异现象。
        """
        return self._sign_with(self._current_salt(), data, t, n)

    @staticmethod
    def _sign_with(salt: str, data: str, t: int, n: str) -> str:
        msg = f"{data}|{t}|{n}"
        return hmac.new(salt.encode(), msg.encode(), hashlib.sha256).hexdigest()

    def _current_salt(self) -> str:
        """当前应当使用的签名盐：会话盐优先，未 init 时用主盐。"""
        return self.session_skey or self.sign_salt

    @staticmethod
    def _gen_machine_id() -> str:
        """生成机器码。生产环境请替换为真实硬件指纹。"""
        raw = f"{os.getenv('COMPUTERNAME', '')}-{os.getenv('PROCESSOR_IDENTIFIER', '')}-{os.getenv('USERNAME', '')}"
        return hashlib.sha256(raw.encode()).hexdigest()[:32]

    # ------------------------------------------------------------------
    # HTTP 层
    # ------------------------------------------------------------------
    def _post(self, action: str, payload: dict) -> dict:
        plain = json.dumps(payload, ensure_ascii=False, separators=(',', ':'))
        data = self._encrypt(plain)
        t = int(time.time())
        n = os.urandom(8).hex()

        body = {
            'data': data,
            'sign': self._sign(data, t, n),      # 自动用会话盐（若已 init）
            't': t,
            'n': n,
        }
        # 会话密钥 id：init 之后所有非公开接口都必须带，
        # 服务端据此取出会话盐验签。缺了它业务接口一律 5002。
        if self.session_kid:
            body['k'] = self.session_kid

        url = f"{self.base_url}/api/index.php?action={action}"
        try:
            r = requests.post(
                url, json=body, timeout=self.timeout, verify=self.verify_ssl,
                headers={'Content-Type': 'application/json'},
            )
        except requests.RequestException as e:
            raise NebulaError(-1, f"网络错误: {e}")

        if r.status_code != 200:
            raise NebulaError(-1, f"HTTP {r.status_code}")

        try:
            outer = r.json()
        except ValueError:
            raise NebulaError(-1, f"响应非 JSON: {r.text[:200]}")

        # 明文响应（调试模式）
        if 'data' not in outer or not isinstance(outer.get('data'), str):
            return outer

        # 验签：盐必须与请求所用一致（会话盐优先）
        if 'sign' in outer and 't' in outer and 'n' in outer:
            expect = self._sign_with(self._current_salt(), outer['data'],
                                     int(outer['t']), str(outer['n']))
            if not hmac.compare_digest(expect, outer['sign']):
                raise NebulaError(-1, "响应签名校验失败，可能存在中间人篡改")

        # 响应加密与请求同算法（AES-256-CBC），完整性由外层 HMAC 签名保证
        try:
            return json.loads(self._decrypt(outer['data']))
        except Exception as e:
            raise NebulaError(-1, f"响应解密失败: {e}")

    def _call(self, action: str, payload: dict, raise_on_error: bool = True) -> dict:
        res = self._post(action, payload)
        code = int(res.get('code', -1))
        if raise_on_error and code != 0:
            raise NebulaError(code, res.get('msg', ''), res.get('data'))
        return res

    # ------------------------------------------------------------------
    # 业务接口
    # ------------------------------------------------------------------
    def init(self, client_ver: str = '1.0.0') -> dict:
        """初始化，返回服务器配置、公告、版本信息"""
        self.client_ver = client_ver
        res = self._call('init', {
            'client_ver': client_ver,
            'machine_id': self.machine_id,
        })
        d = res.get('data', {})

        # ---- 会话级签名密钥（必须保存，否则后续业务接口全部 5002）----
        # 服务端在 init 响应里下发 session: { k, s }：
        #   k = 密钥 id（放请求信封 k 字段）
        #   s = 密钥本体（用作签名盐）
        # 同一 machine_id 每次 init 只保留最新一把，7 天未续自动过期。
        sess = d.get('session')
        if isinstance(sess, dict):
            kid  = str(sess.get('k', '') or '')
            skey = str(sess.get('s', '') or '')
            if kid and skey:
                self.session_kid  = kid
                self.session_skey = skey

        return d

    def register(self, username: str, password: str, email: str = '') -> dict:
        res = self._call('register', {
            'username': username,
            'password': password,
            'email': email,
        })
        return res.get('data', {})

    def login(self, username: str, password: str,
              device_name: str = 'Python Client', os_info: str = '') -> dict:
        """登录，成功后将 token 保存到实例"""
        res = self._call('login', {
            'username': username,
            'password': password,
            'machine_id': self.machine_id,
            'device_name': device_name,
            'os_info': os_info,
            'client_ver': self.client_ver,
        })
        d = res.get('data', {})
        self.token = d.get('token', '')
        self.user = d.get('user', {})
        return d

    def heartbeat(self) -> dict:
        """心跳。返回数据中的 kick / need_relogin 为 True 时应停止业务"""
        res = self._call('heartbeat', {
            'token': self.token,
            'machine_id': self.machine_id,
        })
        return res.get('data', {})

    def activate(self, code: str) -> dict:
        """激活卡密"""
        res = self._call('activate', {
            'token': self.token,
            'machine_id': self.machine_id,
            'code': code,
        })
        return res.get('data', {})

    def unbind(self, password: str = '', all_devices: bool = False) -> dict:
        """解绑设备"""
        res = self._call('unbind', {
            'token': self.token,
            'machine_id': self.machine_id,
            'password': password,
            'all': all_devices,
        })
        return res.get('data', {})

    def devices(self) -> dict:
        """设备列表"""
        res = self._call('devices', {'token': self.token})
        return res.get('data', {})

    def userinfo(self) -> dict:
        """用户信息"""
        res = self._call('userinfo', {'token': self.token})
        return res.get('data', {})

    def notice(self, notice_id: int = 0) -> dict:
        """公告"""
        payload = {'id': notice_id} if notice_id else {}
        res = self._call('notice', payload)
        return res.get('data', {})

    def check_version(self, version: str = '', channel: str = 'stable') -> dict:
        """版本校验"""
        res = self._call('version', {
            'version': version or self.client_ver,
            'channel': channel,
        })
        return res.get('data', {})

    def logout(self) -> None:
        """退出登录"""
        if self.token:
            try:
                self._call('logout', {'token': self.token})
            finally:
                self.token = ''

    # ------------------------------------------------------------------
    # 心跳线程
    # ------------------------------------------------------------------
    def start_heartbeat(self, on_kick=None, on_error=None, interval: int = 60):
        """
        启动心跳循环（阻塞）。建议在独立线程中调用。

        :param on_kick:  被踢下线时的回调 fn(reason: str)
        :param on_error: 出错时的回调 fn(exc: Exception)
        :param interval: 心跳间隔（秒），init 返回的 heartbeat_interval 优先
        """
        while True:
            try:
                data = self.heartbeat()
                # 服务端可能下发新的间隔
                interval = int(data.get('next_interval', interval))
                if data.get('force_offline'):
                    if on_kick:
                        on_kick('force_offline')
                    return
            except NebulaError as e:
                # 被踢下线 / 过期 / 设备解绑
                if e.code in (1002, 1003, 2002, 2004, 4002):
                    reason = e.msg
                    if isinstance(e.data, dict):
                        reason = e.data.get('msg', e.msg)
                    if on_kick:
                        on_kick(reason)
                    return
                if on_error:
                    on_error(e)
                else:
                    raise
            except Exception as e:
                if on_error:
                    on_error(e)
                else:
                    raise

            time.sleep(interval)


# ======================================================================
# 使用示例
# ======================================================================
def demo():
    import sys

    API = 'http://127.0.0.1'
    AES_KEY = 'CHANGE_ME_32_BYTES_AES_KEY_0001'
    SIGN_SALT = 'CHANGE_ME_HMAC_SIGN_SALT'

    cli = NebulaClient(API, AES_KEY, SIGN_SALT)

    print('=' * 55)
    print('1. 初始化')
    print('=' * 55)
    try:
        info = cli.init('1.0.0')
        print(f"  站点名称:   {info.get('site_name')}")
        print(f"  心跳间隔:   {info.get('heartbeat_interval')} 秒")
        print(f"  会话有效期: {info.get('session_ttl')} 秒")
        print(f"  注册开放:   {info.get('register_enable')}")
        v = info.get('version', {})
        print(f"  最新版本:   {v.get('latest')}  (强制更新: {v.get('force_update')})")
        if v.get('force_update'):
            print(f"  [!] 需要强制更新: {v.get('update_url')}")
            return
        for n in info.get('notices', []):
            print(f"  [公告] {n.get('title')}: {n.get('content')}")
    except NebulaError as e:
        print(f"  初始化失败: {e}")
        return

    print()
    print('=' * 55)
    print('2. 注册（若已存在会失败，可忽略）')
    print('=' * 55)
    try:
        r = cli.register('demouser', '123456')
        print(f"  注册成功: user_id={r.get('user_id')}")
    except NebulaError as e:
        print(f"  {e}")

    print()
    print('=' * 55)
    print('3. 登录')
    print('=' * 55)
    try:
        d = cli.login('demouser', '123456')
        print(f"  token: {cli.token[:32]}...")
        u = d.get('user', {})
        print(f"  用户: {u.get('username')}  会员: {u.get('vip_text')}  点数: {u.get('points')}")
        dev = d.get('device', {})
        print(f"  设备: {dev.get('bound_count')}/{dev.get('max_devices')}  自动绑定: {dev.get('auto_bound')}")
    except NebulaError as e:
        if e.code == 2004:
            print(f"  账号未激活: {e.msg}")
            print("  （需先用激活码激活）")
        else:
            print(f"  登录失败: {e}")
        return

    print()
    print('=' * 55)
    print('4. 心跳')
    print('=' * 55)
    try:
        hb = cli.heartbeat()
        print(f"  在线: {hb.get('online')}  剩余: {hb.get('remain_text')}")
        print(f"  下次心跳: {hb.get('next_interval')} 秒后")
    except NebulaError as e:
        print(f"  心跳失败: {e}")

    print()
    print('=' * 55)
    print('5. 激活卡密')
    print('=' * 55)
    try:
        a = cli.activate('TEST-TEST-TEST-TEST')
        print(f"  激活成功: {a.get('detail')}")
        print(f"  会员到期: {a.get('user', {}).get('vip_text')}")
    except NebulaError as e:
        print(f"  {e}")

    print()
    print('=' * 55)
    print('6. 设备列表')
    print('=' * 55)
    try:
        dv = cli.devices()
        print(f"  已绑定 {dv.get('bound_count')}/{dv.get('max_devices')} 台")
        for d in dv.get('devices', []):
            flag = '在线' if d.get('online') else '离线'
            print(f"    - {d.get('device_name')}  [{flag}]  {d.get('last_seen')}")
    except NebulaError as e:
        print(f"  {e}")

    print()
    print('=' * 55)
    print('7. 版本校验')
    print('=' * 55)
    try:
        v = cli.check_version('1.0.0')
        print(f"  当前 {v.get('current')} -> 最新 {v.get('latest')}")
        print(f"  需要更新: {v.get('need_update')}  强制: {v.get('force_update')}")
    except NebulaError as e:
        print(f"  {e}")


if __name__ == '__main__':
    demo()
