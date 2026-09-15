import paramiko, time

SITE = '/home/mmbr/domains/yz.baige.fun/public_html'
ADM  = SITE + '/nb1b4300'

FILES = [
    (r'D:\phpstudy_pro\WWW\lib\Software.php',                     SITE + '/lib/Software.php'),
    (r'D:\phpstudy_pro\WWW\lib\bootstrap.php',                    SITE + '/lib/bootstrap.php'),
    (r'D:\phpstudy_pro\WWW\nb9b6f51\assets\js\pages\software.js',  ADM + '/assets/js/pages/software.js'),
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

sftp = ssh.open_sftp()
for local, remote in FILES:
    sftp.put(local, remote)
    print('[OK] 上传', remote, flush=True)
sftp.close()

run('chmod 644 %s %s %s' % (SITE + '/lib/Software.php',
                            SITE + '/lib/bootstrap.php',
                            ADM + '/assets/js/pages/software.js'))
print('[核对] 会话清理代码:', run('grep -c "清空该软件会话" ' + SITE + '/lib/Software.php')[0], flush=True)
print('[核对] 版本号:', run('grep NB_VERSION ' + SITE + '/lib/bootstrap.php')[0], flush=True)
print('[核对] 语法:', run('/usr/local/bin/php -l ' + SITE + '/lib/Software.php 2>&1')[0], flush=True)
print('[核对] JS 文案:', run('grep -c "全部登录会话会被清空" ' + ADM + '/assets/js/pages/software.js')[0], flush=True)

ssh.close()
print('DONE', flush=True)
