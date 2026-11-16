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

모든 파일은 index.php 파일을 통과

* Q1 : 골격 *
```
다음을 만족하는 index.php 파일을 만들어줘.
HTML5와 Bootstrap5를 이용해 반응형 홈페이지를 만들거야.
상단에는 Navbar를 이용해서 메뉴를 구성할거야.
메뉴에는 menu1, menu2, menu3이 있어.
menu1에는 menu1-1, menu1-2, menu1-3,
menu2에는 menu2-1, menu2-2,
menu3에는 menu3-1, menu3-2로 dropdown형태로 구성할거야.

내용(body) 부분에는 하단의 cmd 결정에 의해 화면을 표시할거야.
하단에는 사이트 정보가 있는데,
"한국생산성본부(KPC)
정보보호책임자: 홍길동(help@kpc.or.kr)"
이렇게 구성되어 있어.

만약에 내용이 너무 적더라도 하단의 사이트정보는 항상 화면의  맨 아래에 배치되도록 구성해 줘.

메뉴부분은 진한 파스텔톤의 녹색 계열로 만들고,
화면은 연한 파스텔톤 녹색 계열로 만들고 싶어.

맨 앞에서는 db.php파일을 추가해 줘.
db.php 파일은 mysql에 접속하는 코드를 함수형태로 만들거야.
index.php에서는 다음과 같이 호출해서 접속을 한번에 해결할거야.
$conn = connectDB(); 

connectDB()를 위해서
$db name = secure
$db user = secure
$db pass = 1111

mysqli()를 사용할거야.

모든 파일은 index.php?cmd=test 와 같은 형태로 cmd값으로
동작을 결정할거야.

GET방식으로 넘어온 $cmd가 없으면
기본값으로 init.php를 본문에 포함할거야.
만약 cmd=test이면 test.php 파일을 추가하도록 코드를 만들거야.

init.php 파일은 일단 다음과 같이 구성할거야.

KPC 보안 프로그래밍 과정

```

**Q2: users table schema 만들기**
```
    mysql 테이블 스키마를 만들고 싶어.
    table name : users
    필요한 정보 : idx, id, name, pass, level
    idx : 정수, 자동증가, primary key
    id : text, unique,
    name : 사용자 이름,
    pass : 50글자의 텍스트, 암호화 하지 않음
    level : 1(일반회원), 2(우수회원), 중간 번호는 예비, 9(관리자)

    1. 스키마를 만들어줘.
    2. 아이디 10개를 만드는 query를 만들어줘.
    admin(관리자), test(테스트)
    나머지는 조선시대 역사 인물 이름으로 무작위로 만들어줘. 모든 비밀번호는 abcd로 세팅하고,
    관리자만 9번, 나머지는 1번을 level로 세팅해
```
**결과**
```sql
CREATE TABLE users (
    idx INT NOT NULL AUTO_INCREMENT,
    id VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    pass VARCHAR(50) NOT NULL,
    level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (idx),
    UNIQUE KEY uk_users_id (id),
    CONSTRAINT chk_users_level
        CHECK (level BETWEEN 1 AND 9)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


  INSERT INTO users (id, name, pass, level) VALUES
('admin',      '관리자',   'abcd', 9),
('test',       '테스트',   'abcd', 1),
('sejong',     '세종대왕', 'abcd', 1),
('yi_sunsin',  '이순신',   'abcd', 1),
('jeong_yakyong', '정약용', 'abcd', 1),
('heogjun',    '허준',     'abcd', 1),
('shinsaimdang', '신사임당', 'abcd', 1),
('kim_hongdo', '김홍도',   'abcd', 1),
('janggyeong', '장영실',   'abcd', 1),
('hwangjini',  '황진이',   'abcd', 1);
```

* Q3: cmd 체크 *
```
조금전 index.php 다음과 같이 수정했어.
$pages = [
    '시큐어코딩' => ['injection', 'injection2', 'injection3'],
    'menu2' => ['menu2-1', 'menu2-2'],
    'menu3' => ['menu3-1', 'menu3-2'],
];


이때, injection.php파일을 만들어야 해.
users 테이블에서 아이디와 비번이 같은지 로그인하는 화면이야.

id, pass를 입력받아서 "로그인"을 수행하면, 
조금 전 만들었던 users 테이블과 비교하는거야.
injection.php 파일은 mysqli_query()와 같은 함수로 질의 하고 싶고, 인젝션 공격을 테스트하기 위한 코드를 만드는 것이 목적이야.

$sql = "select * from users where id='$id' and pass='$pass' "; 와 같은 형식의 코드를 사용해 줘.

index.php 파일에서 세션을 저장하기 위해 sess라는 폴더를 만들었어.
id, name, level
즉 세션처리를 위해 index.php파일과 injection.php 파일을 제시해 줘.
```