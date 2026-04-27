# ovpn.com-ip-grabber

Automatically scans all [OVPN.com](https://ovpn.com) VPN server IPs and commits the results to this repository.

## How it works

1. Scrapes `status.ovpn.com` 
3. Brute-forces `vpn{0..1000}.prd.{city}.ovpn.com` with `dig` to collect all IPs
5. Commits results  back to this repo `results/servers.json`

## Results

| File | Description |
|---|---|
| [`results/servers.json`](results/servers.json) | Full scan output |
| [`results/meta.txt`](results/meta.txt) | Timestamp + totals of the last run |

## JSON structure

```json
[
  {
    "city": "Offenbach",
    "country": "Germany",
    "iso": "DE",
    "slug": "offenbach",
    "ips": ["185.157.162.6", "185.157.162.7"],
    "dns": [
      "185-157-162-6.pool.ovpn.com",
      "185-157-162-7.pool.ovpn.com"
    ]
  }
]
```

## Local usage

```bash
# Cities + slugs only (no dig)
php ovpn_cities.php

# Full scan with IPs and pool DNS
php ovpn_cities.php --dig
```

**Requirements:** PHP 8.x with `ext-curl`, `ext-intl`, `ext-dom`, and `dig` installed.

## License

MIT
