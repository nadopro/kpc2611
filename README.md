# 웹 취약점 분석과 시큐어코딩 (2026.11)

# 1일차

## 1.1 실습용 프로그램 다운로드

## https://github.com/nadopro/kpc2611

[github 실시간 코드 배포](https://github.com/nadopro/kpc2611)

[download xampp](https://www.google.com/search?q=download+xampp&oq=download+xampp&gs_lcrp=EgZjaHJvbWUyBggAEEUYOTIGCAEQABgeMgYIAhAAGB4yBggDEAAYHjIGCAQQABgeMgYIBRAAGB4yBggGEAAYHjIGCAcQABgeMgYICBAAGB4yBggJEAAYHtIBCTM4ODNqMGoxNagCCLACAQ&sourceid=chrome&source=chrome.rb&ie=UTF-8)

> 실행할 때 "Windows의 PC 보호" 파란 창이 뜨면,  
> "추가정보" 클릭 후, 실행  
> 기본 설정, 설치 위치 c:/xampp  

[download visual code](https://www.google.com/search?q=download+visual+code&sca_esv=c0e41f13f2bcea41&sxsrf=AKL5yDirYY0Z88GN1X9mjGqp8aZVOsPQuQ%3A1794786927285&ei=b0b6as72EOvk2roP5JW12Qs&biw=1289&bih=566&uact=5&oq=download+visual+code&gs_lp=Egxnd3Mtd2l6LXNlcnAiFGRvd25sb2FkIHZpc3VhbCBjb2RlMgUQABiABDIEEAAYHjIEEAAYHjIEEAAYHjIEEAAYHjIEEAAYHjIEEAAYHjIEEAAYHjIEEAAYHjIEEAAYHkiBMVCTF1ioMHACeAGQAQCYAWSgAckIqgEEMTEuMbgBA8gBAPgBAZgCDqACvAnCAgoQABhHGNYEGLADwgIXEC4Y3AYYuAYY2gYY2AIYyAMYsAPYAQHCAgoQABiABBiKBRhDwgIGEAAYHhgKmAMAiAYBkAYQugYGCAEQARgZkgcDNy43oAf_QbIHAzUuN7gHqgnCBwcwLjUuNS40yAdMgAgB&sclient=gws-wiz-serp)

### 1.1.1 설치 확인
- [http://localhost](http://localhost)

- [http://localhost/phpmyadmin](http://localhost/phpmyadmin)

* 만약 mysql 사용자 계정 추가가 안되는 경우 *

C:\xampp\phpMyAdmin\config.inc.php 파일의 아래와 같이

cookie 부분 수정, 

DisableIS 부분 추가

후, Apache 재시작

```php
<?php
/* Authentication type and info */
$cfg['Servers'][$i]['auth_type'] = 'cookie'; // 수정
$cfg['Servers'][$i]['user'] = 'root';
$cfg['Servers'][$i]['password'] = '';
$cfg['Servers'][$i]['extension'] = 'mysqli';
$cfg['Servers'][$i]['AllowNoPassword'] = true;
$cfg['Lang'] = '';
$cfg['Servers'][$i]['DisableIS'] = true; // 추가
?>
```
### 1.1.2 기타 프로그램 

[download burp suite community edition](https://www.google.com/search?q=download+burp+suite+community+edition&oq=download+burp+suite+&gs_lcrp=EgZjaHJvbWUqBwgBEAAYgAQyCAgAEEUYHhg5MgcIARAAGIAEMgYIAhAAGB4yBggDEAAYHjIGCAQQABgeMgYIBRAAGB4yBggGEAAYHjIGCAcQABgeMgYICBAAGB4yBggJEAAYHtIBCDk4MzlqMGo0qAIAsAIB&sourceid=chrome&source=chrome.ob&ie=UTF-8)

[download zaproxy](https://www.zaproxy.org/)


## 2. Regular Expression

```
    [abc]  : a, b, c 중에 하나  
    ls *.[ch]  
    [abc]{2} : a, b, c중에서 골라서 2개
    ac, cc, ca, da  
    [abc]{2,4} : 2~4글자
    aaa, aca, bca
    [abc....xyz]{4,10}
    test, desktop, secure
    [a-zA-Z0-9]{4,10}
    Test, Test12, abcd343

    ^010-[0-9]{4}-[0-9]{4}$
    010-123a-5678Test010-1111-2222Test

    Uni + code
    1B : 256
    2B : 65536
    한국어 : 됬다 -> 됐다
    문법 : 3400자.
    조합가능 : 11000자

    한글은 국어사전에 나열된 순서대로 번호를 부여

    AC00 : 가
    AC01 : 각
    AC02 : 갂
    AC03 : 갃
    ...
    LAST : 힣

    ^[가-힣]{2,4}$

    3B : 1600만
    4B : 40억

match, mapping, mother, mat
홍길동 이순신 세종대왕 a강b감c찬
전화번호010-1a11-1111010-1234-5678test
match, mapping, mother, 
홍길동 이순신 세종대왕 a강b감c찬
전화번호010-1a11-1111010-1234-5678test
match, mapping, mother, 
홍길동 이순신 세종대왕 a강b감c찬
전화번호010-1a11-1111010-1234-5678test

```

* SQL Injection *
```
    프로그래머들의 습성을 이용

    $id = "test";
    $pass = "1111";

    $sql = "select * from users where id='$id' and pass='$pass' ";


    "select * from users where id='test' and pass='1111' ";
    id : xxx' or 2>1 limit 1, 1 -- 
    pass : zzz 
    "select * from users where id='xxx' or 2>1 1, 1 -- ' and pass='zzz' ";

```
* 용어 설명 *

> X : Ex, Windows Expert  
> XML : Ex  
> X : Cross Site Scripting : XSS  
> X : AI Transmission, Xmit  
> N : En, Nginx  


## 3. 홈페이지 구조 (index.php, db.php)

