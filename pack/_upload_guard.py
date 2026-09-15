import paramiko, time

SITE = '/home/mmbr/domains/yz.baige.fun/public_html'
ADM  = SITE + '/nb1b4300'

FILES = [
    (r'D:\phpstudy_pro\WWW\lib\Guard.php',                      SITE + '/lib/Guard.php'),
    (r'D:\phpstudy_pro\WWW\lib\bootstrap.php',                  SITE + '/lib/bootstrap.php'),
    (r'D:\phpstudy_pro\WWW\assets\guard.js',                    SITE + '/assets/guard.js'),
    (r'D:\phpstudy_pro\WWW\web\index.php',                      SITE + '/web/index.php'),
    (r'D:\phpstudy_pro\WWW\web\api.php',                        SITE + '/web/api.php'),
    (r'D:\phpstudy_pro\WWW\web\assets\js\site.js',              SITE + '/web/assets/js/site.js'),
    (r'D:\phpstudy_pro\WWW\shop\index.php',                     SITE + '/shop/index.php'),
    (r'D:\phpstudy_pro\WWW\shop\api.php',                       SITE + '/shop/api.php'),
    (r'D:\phpstudy_pro\WWW\shop\assets\shop.js',                SITE + '/shop/assets/shop.js'),
    (r'D:\phpstudy_pro\WWW\agent\index.php',                    SITE + '/agent/index.php'),
    (r'D:\phpstudy_pro\WWW\agent\api.php',                      SITE + '/agent/api.php'),
    (r'D:\phpstudy_pro\WWW\agent\assets\js\agent.js',           SITE + '/agent/assets/js/agent.js'),
    (r'D:\phpstudy_pro\WWW\nb9b6f51\index.php',                 ADM + '/index.php'),
    (r'D:\phpstudy_pro\WWW\nb9b6f51\home.php',                  ADM + '/home.php'),
    (r'D:\phpstudy_pro\WWW\nb9b6f51\AdminAuth.php',             ADM + '/AdminAuth.php'),
    (r'D:\phpstudy_pro\WWW\nb9b6f51\handlers\login.php',        ADM + '/handlers/login.php'),
    (r'D:\phpstudy_pro\WWW\nb9b6f51\assets\js\core\api.js',     ADM + '/assets/js/core/api.js'),
    (r'D:\phpstudy_pro\WWW\nb9b6f51\assets\js\pages\software.js', ADM + '/assets/js/pages/software.js'),
]

def conn(retries=8, wait=70):
    last = None
    for i in range(retries):
        try:
            c = paramiko.SSHClient()
            c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
            c.connect('s8.serv00.com', 22, username='mmbr',
                      password='x$5xvc6AZ#5kd3k%nWkj',
                      timeout=45, banner_timeout=70, auth_timeout=45)
            print('[OK] SSH 连接成功（第 %d 次尝试）' % (i + 1), flush=True)
            return c
        except Exception as e:
            last = e
            print('[..] 第 %d 次失败 %s，%ds 后重试' % (i + 1, type(e).__name__, wait), flush=True)
            time.sleep(wait)
    raise last

ssh = conn()

def run(cmd):
    _i, o, e = ssh.exec_command(cmd)
    return (o.read().decode('utf-8', 'ignore').strip(),
            e.read().decode('utf-8', 'ignore').strip())

# 后台目录名确认（安装时随机生成）
out, _ = run('ls -d %s/nb* 2>/dev/null' % SITE)
print('[信息] 后台目录:', out, flush=True)

sftp = ssh.open_sftp()
for local, remote in FILES:
    sftp.put(local, remote)
    print('[OK] 上传', remote.replace(SITE, ''), flush=True)
sftp.close()

run('chmod 644 %s' % ' '.join(r for _l, r in FILES))

print('[核对] Guard.php 存在:', run('test -f %s/lib/Guard.php && echo YES || echo NO' % SITE)[0], flush=True)
print('[核对] guard.js 存在:', run('test -f %s/assets/guard.js && echo YES || echo NO' % SITE)[0], flush=True)
print('[核对] 版本号:', run('grep -o "NB_VERSION., .[0-9.]*" %s/lib/bootstrap.php' % SITE)[0], flush=True)
print('[核对] 后台风控接入:', run('grep -c "Guard::assess" %s/index.php' % ADM)[0], flush=True)
print('[核对] 二次校验白名单:', run('grep -c "software_reset_keys" %s/index.php' % ADM)[0], flush=True)
print('[核对] 语法 Guard:', run('/usr/local/bin/php -l %s/lib/Guard.php 2>&1' % SITE)[0], flush=True)
print('[核对] 语法 web/api:', run('/usr/local/bin/php -l %s/web/api.php 2>&1' % SITE)[0], flush=True)
print('[核对] 语法 admin/index:', run('/usr/local/bin/php -l %s/index.php 2>&1' % ADM)[0], flush=True)
print('[核对] 站点状态:', run('curl -s -o /dev/null -w "%{http_code}" https://yz.baige.fun/web/')[0], flush=True)

ssh.close()
print('DONE', flush=True)
