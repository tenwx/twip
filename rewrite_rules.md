# Rewrite Rules


## Apache

Just set `AllowOverride` in you Host, Apache will follow `.htaccess` rules provided in twip.

## Nginx(with php-fpm)

Example:

```nginx
server {
    listen      443 ssl spdy;
    server_name m.example.net; 
    ssl on;
    ssl_certificate /path/to/cert.crt;
    ssl_certificate_key /path/to/privkey.pem;
    ssl_prefer_server_ciphers   on;
    client_max_body_size 8m;
    gzip     on;
    index    index.php;

    root     /srv/http/twitter;
    location /twip/oauth { deny all; }
    location /twip/ { try_files $uri /twip/index.php?$args; }
    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php-fpm/php-cgi.socket;
    }
}
```

Note:

* In the example, twip source is located at: `/srv/http/twitter/twip`, and twip is working at `https://m.example.net/twip`.
* If you want to change to some other directory, change `root` / `location /twip/oauth` / `location /twip/`
* Remember to protect `/twip/oauth/` from leaking info when you're adjusting rules.


## lightTPD
    
(Sorry I'm not familar with lightTPD, can't provide a working example here, text below was provided by someone other. If you want to help other lightTPD users, fire an issue at github with a full example.)


For lightTPD users, please use the following rules:

```
url.rewrite-if-not-file += ( "^/(.*)$" => "/index.php/$1" )
```

_Provided by kk198_


Just a reminder, please specify the index-file to "index.php"

```
^/twip/(.*)$ /twip/index.php
```

instead of 

```
^/(.*)$. /index.php

index-file.names   = ( "index.php", "index.html",
        "index.htm", "default.htm" )
```
