This module is currently at "1.0" status.  It should go into a "idealpaymentgateway" directory in your LemonStand modules directory.

You will need to generate a self-signed private key and certificate (unless you already have them).  Use the following commands to do so:

__Please note that these have changed for iDEAL v. 3.3.1!__

```
	/usr/bin/env openssl genrsa -aes128 -out priv.pem -passout pass:[privateKeyPass] 2048
	/usr/bin/env openssl req -x509 -new -key priv.pem -passin pass:[privateKeyPass] -days 1825 -out cert.cer
```

In the module settings, you can either provide the path to the file or copy and paste the values.  If you're on OS X, use: `cat filename | pbcopy` to copy a file's contents to the clipboard.