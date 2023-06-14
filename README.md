# CachePurger for Varnish

- supports clear per page or all pages
- supports TYPO3 >= 11.5
- requires php >= 8.1

# Example configuration in Typoscript
```
tx_cachepurger.settings {
    varnish {
        1 = varnish_ip_frontend
        2 = varnish_ip_backend
    }
    tags.0 = T3
}
```
