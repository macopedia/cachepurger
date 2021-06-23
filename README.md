# CachePurger for Varnish 

- supports clear per page or all pages
- supports TYPO3 9.5-10.4
- requires php >= 7.4

# Example configuration in Typoscript
```
tx_cachepurger.settings {
    varnish {
        1 = varnish_ip_frontend
        2 = varnish_ip_backend
    }
    domains {
        1 = https://frontend_url.tld
        2 = https://backend_url.tld
    }
    tags.0 = T3
}
```
