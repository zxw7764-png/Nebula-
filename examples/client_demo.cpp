// ============================================================
// Nebula 网络验证 - C++ 客户端对接示例
//
// 依赖:
//   - libcurl  (HTTP 请求)
//   - OpenSSL  (AES-256-CBC / HMAC-SHA256 / Base64)
//   - nlohmann/json (JSON 解析，单头文件即可)
//
// 编译示例 (MSVC):
//   cl /std:c++17 /EHsc client_demo.cpp /I. ^
//      /link libcurl.lib libssl.lib libcrypto.lib ws2_32.lib crypt32.lib
//
// 编译示例 (MinGW):
//   g++ -std=c++17 client_demo.cpp -o client_demo.exe -lcurl -lssl -lcrypto
// ============================================================

#include <string>
#include <vector>
#include <iostream>
#include <ctime>
#include <random>
#include <sstream>
#include <iomanip>
#include <stdexcept>

#include <curl/curl.h>
#include <openssl/aes.h>
#include <openssl/evp.h>
#include <openssl/hmac.h>
#include <openssl/sha.h>
#include <openssl/md5.h>

#include "json.hpp"   // nlohmann/json

using json = nlohmann::json;

// ============================================================
// 配置区（与 install 安装完成后显示的密钥保持一致）
// ============================================================
static const std::string API_BASE   = "http://127.0.0.1/api/index.php";
static const std::string AES_KEY    = "CHANGE_ME_32_BYTES_AES_KEY_0001";
static const std::string SIGN_SALT  = "CHANGE_ME_HMAC_SIGN_SALT";

// ============================================================
// 工具函数
// ============================================================
namespace xr {

// ---------------- Base64 ----------------
static const char* B64 =
    "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

std::string base64Encode(const std::vector<unsigned char>& in) {
    std::string out;
    int val = 0, bits = -6;
    for (unsigned char c : in) {
        val = (val << 8) + c;
        bits += 8;
        while (bits >= 0) {
            out.push_back(B64[(val >> bits) & 0x3F]);
            bits -= 6;
        }
    }
    if (bits > -6) out.push_back(B64[((val << 8) >> (bits + 8)) & 0x3F]);
    while (out.size() % 4) out.push_back('=');
    return out;
}

std::vector<unsigned char> base64Decode(const std::string& s) {
    std::vector<int> T(256, -1);
    for (int i = 0; i < 64; i++) T[(unsigned char)B64[i]] = i;

    std::vector<unsigned char> out;
    int val = 0, bits = -8;
    for (unsigned char c : s) {
        if (T[c] == -1) continue;
        val = (val << 6) + T[c];
        bits += 6;
        if (bits >= 0) {
            out.push_back((unsigned char)((val >> bits) & 0xFF));
            bits -= 8;
        }
    }
    return out;
}

// ---------------- Hex ----------------
std::string toHex(const unsigned char* data, size_t len) {
    std::ostringstream oss;
    oss << std::hex << std::setfill('0');
    for (size_t i = 0; i < len; i++)
        oss << std::setw(2) << (int)data[i];
    return oss.str();
}

// ---------------- 派生 32 字节 AES Key ----------------
std::vector<unsigned char> sha256(const std::string& in) {
    std::vector<unsigned char> out(SHA256_DIGEST_LENGTH);
    SHA256((const unsigned char*)in.data(), in.size(), out.data());
    return out;
}

std::vector<unsigned char> aesKey() {
    auto full = sha256(AES_KEY);
    return std::vector<unsigned char>(full.begin(), full.begin() + 32);
}

std::vector<unsigned char> aesIv() {
    // 与服务端一致: md5(aes_key) 前 16 字节
    std::vector<unsigned char> out(16);
    MD5((const unsigned char*)AES_KEY.data(), AES_KEY.size(), out.data());
    return out;
}

// ---------------- AES-256-CBC 加密 ----------------
std::string aesEncrypt(const std::string& plain) {
    auto key = aesKey();
    auto iv  = aesIv();

    EVP_CIPHER_CTX* ctx = EVP_CIPHER_CTX_new();
    if (!ctx) throw std::runtime_error("EVP_CIPHER_CTX_new failed");

    std::vector<unsigned char> out(plain.size() + 32);
    int len = 0, total = 0;

    EVP_EncryptInit_ex(ctx, EVP_aes_256_cbc(), nullptr, key.data(), iv.data());
    EVP_EncryptUpdate(ctx, out.data(), &len,
                      (const unsigned char*)plain.data(), (int)plain.size());
    total = len;
    EVP_EncryptFinal_ex(ctx, out.data() + len, &len);
    total += len;
    EVP_CIPHER_CTX_free(ctx);

    // 拼 iv + ciphertext 再 base64
    std::vector<unsigned char> packed;
    packed.insert(packed.end(), iv.begin(), iv.end());
    packed.insert(packed.end(), out.begin(), out.begin() + total);
    return base64Encode(packed);
}

// ---------------- AES-256-CBC 解密 ----------------
std::string aesDecrypt(const std::string& b64) {
    auto raw = base64Decode(b64);
    if (raw.size() <= 16) throw std::runtime_error("cipher too short");

    auto key = aesKey();
    std::vector<unsigned char> iv(raw.begin(), raw.begin() + 16);
    std::vector<unsigned char> ct(raw.begin() + 16, raw.end());

    EVP_CIPHER_CTX* ctx = EVP_CIPHER_CTX_new();
    std::vector<unsigned char> out(ct.size() + 32);
    int len = 0, total = 0;

    EVP_DecryptInit_ex(ctx, EVP_aes_256_cbc(), nullptr, key.data(), iv.data());
    EVP_DecryptUpdate(ctx, out.data(), &len, ct.data(), (int)ct.size());
    total = len;
    if (EVP_DecryptFinal_ex(ctx, out.data() + len, &len) != 1) {
        EVP_CIPHER_CTX_free(ctx);
        throw std::runtime_error("decrypt final failed");
    }
    total += len;
    EVP_CIPHER_CTX_free(ctx);

    return std::string((char*)out.data(), total);
}

// ---------------- HMAC-SHA256 签名 ----------------
std::string hmacSign(const std::string& data, long long t, const std::string& n) {
    std::string msg = data + "|" + std::to_string(t) + "|" + n;
    unsigned char mac[EVP_MAX_MD_SIZE];
    unsigned int macLen = 0;
    HMAC(EVP_sha256(),
         SIGN_SALT.data(), (int)SIGN_SALT.size(),
         (const unsigned char*)msg.data(), msg.size(),
         mac, &macLen);
    return toHex(mac, macLen);
}

// ---------------- 随机 nonce ----------------
std::string randomNonce() {
    static std::mt19937_64 rng(std::random_device{}());
    std::ostringstream oss;
    oss << std::hex << rng() << rng();
    return oss.str();
}

// ---------------- libcurl 回调 ----------------
static size_t writeCb(void* ptr, size_t size, size_t nmemb, void* userdata) {
    ((std::string*)userdata)->append((char*)ptr, size * nmemb);
    return size * nmemb;
}

// ============================================================
// HTTP 请求：自动加密 + 签名
// ============================================================
json post(const std::string& action, const json& payload) {
    // 1. 构造明文 JSON
    std::string plain = payload.dump();

    // 2. 加密
    std::string data  = aesEncrypt(plain);

    // 3. 签名
    long long t       = (long long)time(nullptr);
    std::string n     = randomNonce();
    std::string sign  = hmacSign(data, t, n);

    // 4. 组装请求体
    json body = {
        {"data", data},
        {"sign", sign},
        {"t",    t},
        {"n",    n}
    };

    // 5. 发送
    CURL* curl = curl_easy_init();
    if (!curl) throw std::runtime_error("curl init failed");

    std::string url = API_BASE + "?action=" + action;
    std::string resp;
    struct curl_slist* headers = nullptr;
    headers = curl_slist_append(headers, "Content-Type: application/json");
    headers = curl_slist_append(headers, "Expect:");

    std::string bodyStr = body.dump();
    curl_easy_setopt(curl, CURLOPT_URL, url.c_str());
    curl_easy_setopt(curl, CURLOPT_POST, 1L);
    curl_easy_setopt(curl, CURLOPT_POSTFIELDS, bodyStr.c_str());
    curl_easy_setopt(curl, CURLOPT_POSTFIELDSIZE, (long)bodyStr.size());
    curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, writeCb);
    curl_easy_setopt(curl, CURLOPT_WRITEDATA, &resp);
    curl_easy_setopt(curl, CURLOPT_TIMEOUT, 15L);
    curl_easy_setopt(curl, CURLOPT_SSL_VERIFYPEER, 0L); // 生产建议开启

    CURLcode rc = curl_easy_perform(curl);
    curl_slist_free_all(headers);
    curl_easy_cleanup(curl);

    if (rc != CURLE_OK) {
        throw std::runtime_error(std::string("curl error: ") + curl_easy_strerror(rc));
    }

    // 6. 解析响应
    json outer = json::parse(resp);

    // 如果服务端返回明文（调试模式），直接返回
    if (!outer.contains("data")) return outer;

    // 解密
    std::string plainResp = aesDecrypt(outer["data"].get<std::string>());
    return json::parse(plainResp);
}

// ============================================================
// 机器码生成（示例：基于硬件信息，请按需替换）
// ============================================================
std::string machineId() {
    // 实际项目建议采集：
    //   主板序列号 + CPU ID + 硬盘序列号 + MAC 地址，再做 SHA256
    // 这里用固定示例值演示
    std::string raw = "DEMO-MACHINE-0001";
    auto h = sha256(raw);
    return toHex(h.data(), 16); // 取前 16 字节
}

} // namespace xr

// ============================================================
// 业务封装
// ============================================================
struct LoginResult {
    bool        ok = false;
    int         code = 0;
    std::string msg;
    std::string token;
    long long   expireAt = 0;
    json        user;
};

class AuthClient {
public:
    std::string token;
    std::string machineId = xr::machineId();

    // 初始化
    json init(const std::string& clientVer = "1.0.0") {
        json p = {
            {"client_ver", clientVer},
            {"machine_id", machineId}
        };
        return xr::post("init", p);
    }

    // 注册
    json regist(const std::string& user, const std::string& pass,
                const std::string& email = "") {
        json p = {
            {"username", user},
            {"password", pass},
            {"email",    email}
        };
        return xr::post("register", p);
    }

    // 登录
    LoginResult login(const std::string& user, const std::string& pass,
                      const std::string& clientVer = "1.0.0") {
        json p = {
            {"username",    user},
            {"password",    pass},
            {"machine_id",  machineId},
            {"device_name", "Windows PC"},
            {"os_info",     "Windows 10 x64"},
            {"client_ver",  clientVer}
        };

        LoginResult r;
        json res = xr::post("login", p);
        r.code = res.value("code", -1);
        r.msg  = res.value("msg", "");

        if (r.code == 0) {
            r.ok = true;
            auto d = res["data"];
            r.token    = d.value("token", "");
            r.expireAt = d.value("expire_at", 0LL);
            r.user     = d.value("user", json::object());
            token      = r.token;
        }
        return r;
    }

    // 心跳
    json heartbeat() {
        json p = {
            {"token",      token},
            {"machine_id", machineId}
        };
        return xr::post("heartbeat", p);
    }

    // 激活卡密
    json activate(const std::string& code) {
        json p = {
            {"token",      token},
            {"machine_id", machineId},
            {"code",       code}
        };
        return xr::post("activate", p);
    }

    // 解绑设备
    json unbind(const std::string& password = "", bool all = false) {
        json p = {
            {"token",      token},
            {"machine_id", machineId},
            {"password",   password},
            {"all",        all}
        };
        return xr::post("unbind", p);
    }

    // 设备列表
    json devices() {
        return xr::post("devices", {{"token", token}});
    }

    // 用户信息
    json userinfo() {
        return xr::post("userinfo", {{"token", token}});
    }

    // 退出
    json logout() {
        return xr::post("logout", {{"token", token}});
    }

    // 版本校验
    json checkVersion(const std::string& ver) {
        return xr::post("version", {{"version", ver}});
    }
};

// ============================================================
// main 演示
// ============================================================
int main() {
    curl_global_init(CURL_GLOBAL_DEFAULT);

    try {
        AuthClient cli;

        std::cout << "===== 1. 初始化 =====" << std::endl;
        json ini = cli.init("1.0.0");
        std::cout << ini.dump(2) << std::endl;

        if (ini.contains("data") && ini["data"].contains("version")) {
            auto v = ini["data"]["version"];
            if (v.value("force_update", false)) {
                std::cout << "[!] 需要强制更新: " << v.value("update_url", "") << std::endl;
                curl_global_cleanup();
                return 0;
            }
        }

        std::cout << "\n===== 2. 登录 =====" << std::endl;
        auto lr = cli.login("testuser", "123456", "1.0.0");
        std::cout << "code=" << lr.code << " msg=" << lr.msg << std::endl;

        if (!lr.ok) {
            std::cout << "登录失败，程序退出" << std::endl;
            curl_global_cleanup();
            return 1;
        }
        std::cout << "token=" << lr.token << std::endl;
        std::cout << "user=" << lr.user.dump(2) << std::endl;

        std::cout << "\n===== 3. 心跳 =====" << std::endl;
        json hb = cli.heartbeat();
        std::cout << hb.dump(2) << std::endl;

        std::cout << "\n===== 4. 激活卡密 =====" << std::endl;
        json act = cli.activate("TEST-TEST-TEST-TEST");
        std::cout << act.dump(2) << std::endl;

        std::cout << "\n===== 5. 设备列表 =====" << std::endl;
        json dev = cli.devices();
        std::cout << dev.dump(2) << std::endl;

        // 心跳循环（实际客户端应放到独立线程）
        // while (true) {
        //     std::this_thread::sleep_for(std::chrono::seconds(60));
        //     json h = cli.heartbeat();
        //     if (h.value("code", -1) != 0) {
        //         // 被踢下线或过期，处理登出逻辑
        //         break;
        //     }
        // }

    } catch (const std::exception& e) {
        std::cerr << "异常: " << e.what() << std::endl;
        curl_global_cleanup();
        return 1;
    }

    curl_global_cleanup();
    return 0;
}
