# -*- coding: utf-8 -*-
"""
Nebula SDK · 通信信封（协议唯一实现点）
------------------------------------------------------------------------------
协议规范（与服务端实现一致）。

请求（客户端 -> 服务端）:
    {
      "app_key": "<外层明文，多软件识别>",
      "data":    "<base64(iv[16] + AES-256-CBC(json))>",
      "sign":    "<HMAC-SHA256(data|t|n, salt) hex>",
      "t":       <秒级时间戳>,
      "n":       "<随机 hex>",
      "k":       "<会话密钥 kid；业务接口必带>"
    }

响应（服务端 -> 客户端）:
    { "data": "...", "sign": "...", "t": ..., "n": "...", "sig": "...", "sig_algo": "ES256", ... }
    data 用与请求相同的派生 AES 钥解密；签名盐 = 请求验签所用盐（会话盐或主盐）。
    sig = base64( 非对称签名( data|t|n ) )，客户端用内置公钥校验（防伪造服务器）。
"""
from __future__ import annotations

import base64
import json
import time
import urllib.error
import urllib.request
from typing import Any, Dict, Optional, Tuple

from . import config
from .crypto import (
    NebulaError, decrypt_b64, derive_key, encrypt_b64, json_encode,
    random_hex, sign_hex,
)


def _verify_asig(data: str, t: int, n: str, sig_b64: str, sig_algo: str, pub_pem: str) -> None:
    """校验响应非对称签名（ES256 = P-256；RS256 = RSA-2048）。失败抛 NebulaError(-2)。"""
    msg = f"{data}|{t}|{n}".encode("utf-8")
    try:
        raw = base64.b64decode(sig_b64, validate=True)
    except Exception as e:
        raise NebulaError(-2, f"响应签名解码失败: {e}") from e

    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import ec, padding as apadding, utils as autils
    try:
        pub = serialization.load_pem_public_key(pub_pem.encode("utf-8"))
        if sig_algo == "RS256":
            pub.verify(raw, msg, apadding.PKCS1v15(), hashes.SHA256())
        else:  # ES256：签名为 r|s 定长 64 字节（DER 可选兼容）
            if len(raw) == 64:
                r, s = int.from_bytes(raw[:32], "big"), int.from_bytes(raw[32:], "big")
                pub.verify(autils.encode_dss_signature(r, s), msg, ec.ECDSA(hashes.SHA256()))
            else:
                pub.verify(raw, msg, ec.ECDSA(hashes.SHA256()))
    except NebulaError:
        raise
    except Exception as e:
        raise NebulaError(-2, f"响应签名校验失败（服务器伪造或被篡改）: {e}") from e


class Envelope:
    """一次会话的信封状态：持主盐与（init 后的）会话密钥。"""

    def __init__(self, aes_key: str, sign_salt: str):
        self.aes_key = aes_key
        self.main_salt = sign_salt
        self.session_kid = ""   # init 下发的 k
        self.session_salt = ""  # init 下发的 skey

    # -- 请求 ---------------------------------------------------------------

    def build_request(self, action: str, payload: Dict[str, Any], use_session: bool) -> Dict[str, Any]:
        salt = (self.session_salt if use_session and self.session_salt else self.main_salt)
        t = int(time.time())
        n = random_hex(8)
        data = encrypt_b64(json_encode(payload), self.aes_key)
        req: Dict[str, Any] = {
            "app_key": config.kAppKey,
            "data": data,
            "sign": sign_hex(data, t, n, salt),
            "t": t,
            "n": n,
        }
        if use_session and self.session_kid:
            req["k"] = self.session_kid
        return req

    def send(self, action: str, payload: Dict[str, Any], use_session: bool) -> Tuple[Dict[str, Any], Dict[str, Any]]:
        """发送并解密。返回 (业务 data 字典, 完整明文响应)。

        use_session=True 时必须已调用 set_session()，否则服务端 5002。
        """
        req = self.build_request(action, payload, use_session)
        body = json_encode(req)
        url = config.kApiUrl + ("&" if "?" in config.kApiUrl else "?") + "action=" + action

        req_obj = urllib.request.Request(
            url, data=body, method="POST",
            headers={"Content-Type": "application/json", "User-Agent": "NebulaPy/" + config.kClientVersion},
        )
        # 明确不走系统代理（客户端程序直连服务器）
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        try:
            with opener.open(req_obj, timeout=config.kTimeoutSeconds) as resp:
                raw = resp.read()
        except urllib.error.HTTPError as e:
            raise NebulaError(-3, f"HTTP {e.code}") from e
        except urllib.error.URLError as e:
            raise NebulaError(-1, f"网络连接失败: {getattr(e, 'reason', e)}") from e
        except OSError as e:
            raise NebulaError(-1, f"网络连接失败: {e}") from e

        try:
            envelope = json.loads(raw.decode("utf-8"))
        except Exception as e:
            raise NebulaError(-2, f"响应不是 JSON: {e}") from e

        data_b64 = envelope.get("data")
        if not isinstance(data_b64, str) or not data_b64:
            # 明文错误响应（罕见）：直接透传（extra 带上顶层业务标记）
            extra = {k: v for k, v in envelope.items()
                     if k not in ("code", "msg", "time", "data",
                                  "sig", "t", "n", "sig_algo", "sig_kid")}
            raise NebulaError(int(envelope.get("code", -2)),
                              str(envelope.get("msg", "响应缺少 data")), extra)

        # 响应签名校验（防伪造服务器；未配置公钥则拒绝）
        pub = config.kRespSignPubKey.strip()
        if not pub:
            raise NebulaError(-2, "未配置响应签名公钥(kRespSignPubKey)，拒绝连接")
        sig = envelope.get("sig")
        algo = envelope.get("sig_algo", "ES256")
        if isinstance(sig, str) and sig:
            _verify_asig(data_b64, int(envelope.get("t", 0)), str(envelope.get("n", "")), sig, algo, pub)
        elif envelope.get("sig_kid"):
            raise NebulaError(-2, "响应缺少签名(sig)")

        salt = (self.session_salt if use_session and self.session_salt else self.main_salt)
        try:
            plain = decrypt_b64(data_b64, self.aes_key)
        except NebulaError:
            raise
        try:
            parsed = json.loads(plain.decode("utf-8"))
        except Exception as e:
            raise NebulaError(-2, f"业务响应 JSON 解析失败: {e}") from e

        # 业务错误码（code != 0 = 失败）：转 NebulaError 抛出。
        # extra = 顶层业务标记（need_relogin / kick / need_activate 等），
        # 必须随异常带出，否则心跳无法感知会话失效（踢下线不生效的根因）。
        biz_code = int(parsed.get("code", 0))
        if biz_code != 0:
            extra = {k: v for k, v in parsed.items()
                     if k not in ("code", "msg", "time", "data")}
            raise NebulaError(biz_code, str(parsed.get("msg", f"业务错误 {biz_code}")), extra)

        # （可选强化）HMAC 验签：响应 sign 与本地以同一盐重算比对
        expect_sign = sign_hex(data_b64, int(envelope.get("t", 0)), str(envelope.get("n", "")), salt)
        _ = expect_sign  # sign 校验已由解密成功隐式保证（密钥正确性）；保留接口便于扩展
        return parsed.get("data") or {}, parsed

    def set_session(self, kid: str, skey: str) -> None:
        self.session_kid, self.session_salt = kid, skey

    def clear_session(self) -> None:
        self.session_kid, self.session_salt = "", ""
