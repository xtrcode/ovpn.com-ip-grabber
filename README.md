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
| [`results/bench.json`](results/bench.json) | Benchmark of all IPs |

## JSON structure

```json
[
   {
        "city": "Oslo",
        "country": "Norway",
        "iso": "NO",
        "slug": "oslo",
        "api_slug": "oslo",
        "servers": [
            {
                "name": "VPN37",
                "ip": "45.148.18.35",
                "pool_dns": "45-148-18-35.pool.ovpn.com",
                "online": true,
                "uptime": "11 hours",
                "bandwidth_mbit": 111,
                "bandwidth_usage": 11,
                "port_speed_mbit": 1000
            },
            {
                "name": "VPN38",
                "ip": "45.148.18.36",
                "pool_dns": "45-148-18-36.pool.ovpn.com",
                "online": true,
                "uptime": "11 hours",
                "bandwidth_mbit": 56,
                "bandwidth_usage": 6,
                "port_speed_mbit": 1000
            },
            {
                "name": "VPN39",
                "ip": "45.148.18.37",
                "pool_dns": "45-148-18-37.pool.ovpn.com",
                "online": true,
                "uptime": "3 days",
                "bandwidth_mbit": 70,
                "bandwidth_usage": 7,
                "port_speed_mbit": 1000
            }
        ],
        "ips": [
            "45.148.18.35",
            "45.148.18.36",
            "45.148.18.37"
        ],
        "dns": [
            "45-148-18-35.pool.ovpn.com",
            "45-148-18-36.pool.ovpn.com",
            "45-148-18-37.pool.ovpn.com"
        ]
    },
]
```

## Local usage

```bash
php ovpn.php --dig --output=results/servers.json

php bench.php --input=results/servers.json --output=results/bench.json
```

**Requirements:** PHP 8.x with `ext-curl`, `ext-intl`, `ext-dom`, and `dig` installed.

## License

MIT
