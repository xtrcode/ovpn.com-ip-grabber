# ovpn.com-ip-grabber

Automatically scans all [OVPN.com](https://ovpn.com) VPN server IPs and commits the results to this repository.

## How it works

1. Scrapes `status.ovpn.com` 
3. Brute-forces `vpn{0..1000}.prd.{city}.ovpn.com` with `dig` to collect all IPs
5. Commits results back to this repo `results/servers.json`

## How to use

1. Generate list of servers
```bash
php ovpn.php --dig --output=results/servers.json
```

<details>
<summary>Show output</summary>

```text
OVPN IP Fetcher
Repository: https://github.com/xtrcode/ovpn-ip-grabber
License: MIT
───────────────────────────────────────────────

Fetching city list from status.ovpn.com...
Cities found: 32

────────────────────────────────────────────────────────────
Fetching servers from API...
────────────────────────────────────────────────────────────
  Amsterdam                 (amsterdam           ) ... 5 server(s), 5 online
  Atlanta                   (atlanta             ) ... 1 server(s), 1 online
  Bucharest                 (bucharest           ) ... 1 server(s), 1 online
  Chicago                   (chicago             ) ... 1 server(s), 1 online
  Copenhagen                (copenhagen          ) ... 1 server(s), 1 online
  Dallas                    (dallas              ) ... 1 server(s), 1 online
  Denver                    (denver              ) ... 1 server(s), 1 online
  Erfurt                    (erfurt              ) ... 2 server(s), 2 online
  Frankfurt                 (frankfurt           ) ... 2 server(s), 2 online
  Gothenburg                (gothenburg          ) ... 12 server(s), 12 online
  Helsinki                  (helsinki            ) ... 1 server(s), 1 online
  Kyiv                      (kyiv                ) ... 1 server(s), 1 online
  London                    (london              ) ... 1 server(s), 1 online
  Los Angeles               (los-angeles         ) ... API error [los-angeles]: HTTP 404 Not Found: https://status.ovpn.com/datacenters/los-angeles/servers
no servers returned
  Madrid                    (madrid              ) ... 1 server(s), 1 online
  Malmö                    (malmo               ) ... 15 server(s), 15 online
  Miami                     (miami               ) ... 1 server(s), 1 online
  Milan                     (milan               ) ... 1 server(s), 1 online
  New York                  (new-york            ) ... API error [new-york]: HTTP 404 Not Found: https://status.ovpn.com/datacenters/new-york/servers
no servers returned
  Offenbach                 (offenbach           ) ... 2 server(s), 2 online
  Oslo                      (oslo                ) ... 3 server(s), 3 online
  Paris                     (paris               ) ... 1 server(s), 1 online
  Seattle                   (seattle             ) ... 1 server(s), 1 online
  Singapore                 (singapore           ) ... 1 server(s), 1 online
  Stockholm                 (stockholm           ) ... API error [stockholm]: HTTP 404 Not Found: https://status.ovpn.com/datacenters/stockholm/servers
no servers returned
  Sundsvall                 (sundsvall           ) ... 4 server(s), 4 online
  Sydney                    (sydney              ) ... 1 server(s), 1 online
  Tokyo                     (tokyo               ) ... 1 server(s), 1 online
  Toronto                   (toronto             ) ... 1 server(s), 1 online
  Vienna                    (vienna              ) ... 1 server(s), 1 online
  Warsaw                    (warsaw              ) ... 1 server(s), 1 online
  Zurich                    (zurich              ) ... 1 server(s), 1 online

────────────────────────────────────────────────────────────
Cities   : 32
Servers  : 66

No servers found for:
  · Los Angeles (slug: los-angeles)
  · New York (slug: new-york)
  · Stockholm (slug: stockholm)
Output written to: results/servers.json (35194 bytes)
```

</details>

2. Run benchmark

```bash
php bench.php --input=results/servers.json
```

<details>
<summary>Show output</summary>

```text
OVPN Bench Tool
───────────────────────────────────────────────
Using ping  : /usr/bin/ping
Ping method : ICMP (/usr/bin/ping)
  ⚠ No servers for Los Angeles, United States — skipping
  ⚠ No servers for New York, United States — skipping
  ⚠ No servers for Stockholm, Sweden — skipping
Loaded 66 server(s) across 32 city/cities from results/servers.json

Pinging servers...
  [1/66] VPN28   185.157.162.6    Amsterdam            ... avg=37.77ms  med=40.90ms  σ=17.05ms  (3/3 probes)
  [2/66] VPN29   185.157.162.7    Amsterdam            ... avg=25.43ms  med=15.80ms  σ=15.06ms  (3/3 probes)
  [3/66] VPN30   185.157.162.8    Amsterdam            ... avg=24.27ms  med=15.10ms  σ=13.25ms  (3/3 probes)
  [4/66] VPN35   185.157.162.9    Amsterdam            ... avg=79.07ms  med=13.20ms  σ=93.29ms  (3/3 probes)
  [5/66] VPN36   185.157.162.10   Amsterdam            ... avg=33.10ms  med=42.10ms  σ=13.80ms  (3/3 probes)
  [6/66] VPN18   45.134.140.67    Atlanta              ... avg=121.67ms  med=115.00ms  σ=9.43ms  (3/3 probes)
  [7/66] VPN45   37.120.206.163   Bucharest            ... avg=94.77ms  med=52.10ms  σ=67.46ms  (3/3 probes)
  [8/66] VPN32   87.249.134.67    Chicago              ... avg=128.00ms  med=134.00ms  σ=8.49ms  (3/3 probes)
  [9/66] VPN27   185.236.203.98   Copenhagen           ... avg=54.47ms  med=55.40ms  σ=6.48ms  (3/3 probes)
  [10/66] VPN33   194.37.97.35     Dallas               ... avg=172.33ms  med=163.00ms  σ=14.64ms  (3/3 probes)
  [11/66] VPN103  169.150.231.243  Denver               ... avg=147.67ms  med=137.00ms  σ=15.08ms  (3/3 probes)
  [12/66] VPN90   84.19.175.164    Erfurt               ... avg=27.97ms  med=21.60ms  σ=10.08ms  (3/3 probes)
  [13/66] VPN93   217.114.215.130  Erfurt               ... avg=31.73ms  med=23.40ms  σ=12.21ms  (3/3 probes)
  [14/66] VPN94   45.141.152.69    Frankfurt            ... avg=40.53ms  med=23.40ms  σ=24.30ms  (3/3 probes)
  [15/66] VPN98   45.141.152.68    Frankfurt            ... avg=27.87ms  med=23.50ms  σ=6.46ms  (3/3 probes)
  [16/66] VPN57   193.187.91.195   Gothenburg           ... avg=49.43ms  med=58.60ms  σ=14.62ms  (3/3 probes)
  [17/66] VPN58   193.187.91.196   Gothenburg           ... avg=74.60ms  med=29.00ms  σ=64.63ms  (3/3 probes)
  [18/66] VPN59   193.187.91.197   Gothenburg           ... avg=57.80ms  med=58.20ms  σ=0.64ms  (3/3 probes)
  [19/66] VPN65   193.187.91.198   Gothenburg           ... avg=37.70ms  med=29.10ms  σ=12.52ms  (3/3 probes)
  [20/66] VPN66   193.187.91.199   Gothenburg           ... avg=39.33ms  med=28.30ms  σ=15.67ms  (3/3 probes)
  [21/66] VPN67   193.187.91.200   Gothenburg           ... avg=29.77ms  med=28.40ms  σ=2.00ms  (3/3 probes)
  [22/66] VPN78   193.187.91.201   Gothenburg           ... avg=50.07ms  med=60.20ms  σ=14.83ms  (3/3 probes)
  [23/66] VPN79   193.187.91.202   Gothenburg           ... avg=46.47ms  med=54.50ms  σ=12.95ms  (3/3 probes)
  [24/66] VPN80   193.187.91.203   Gothenburg           ... avg=28.23ms  med=28.10ms  σ=0.19ms  (3/3 probes)
  [25/66] VPN81   193.187.91.204   Gothenburg           ... avg=87.20ms  med=56.80ms  σ=61.52ms  (3/3 probes)
  [26/66] VPN82   193.187.91.205   Gothenburg           ... avg=45.87ms  med=55.80ms  σ=14.26ms  (3/3 probes)
  [27/66] VPN83   193.187.91.206   Gothenburg           ... avg=40.50ms  med=25.60ms  σ=21.21ms  (3/3 probes)
  [28/66] VPN104  46.246.34.51     Helsinki             ... avg=49.57ms  med=45.00ms  σ=6.53ms  (3/3 probes)
  [29/66] VPN96   143.244.46.147   Kyiv                 ... avg=56.43ms  med=60.10ms  σ=9.58ms  (3/3 probes)
  [30/66] VPN68   89.238.176.3     London               ... avg=28.67ms  med=18.30ms  σ=14.66ms  (3/3 probes)
  [31/66] VPN70   192.145.124.3    Madrid               ... avg=124.27ms  med=67.30ms  σ=97.88ms  (3/3 probes)
  [32/66] VPN01   185.157.163.3    Malmö               ... avg=26.87ms  med=25.30ms  σ=3.41ms  (3/3 probes)
  [33/66] VPN06   185.157.163.4    Malmö               ... avg=34.47ms  med=23.70ms  σ=15.30ms  (3/3 probes)
  [34/66] VPN07   185.157.163.5    Malmö               ... avg=35.80ms  med=30.10ms  σ=10.78ms  (3/3 probes)
  [35/66] VPN08   185.157.163.6    Malmö               ... avg=34.60ms  med=23.30ms  σ=16.62ms  (3/3 probes)
  [36/66] VPN40   185.157.163.7    Malmö               ... avg=23.20ms  med=23.20ms  σ=0.08ms  (3/3 probes)
  [37/66] VPN41   185.157.163.8    Malmö               ... avg=22.63ms  med=22.60ms  σ=0.21ms  (3/3 probes)
  [38/66] VPN42   185.157.163.9    Malmö               ... avg=33.17ms  med=23.20ms  σ=14.17ms  (3/3 probes)
  [39/66] VPN49   185.157.163.10   Malmö               ... avg=87.63ms  med=54.80ms  σ=69.28ms  (3/3 probes)
  [40/66] VPN50   185.157.163.11   Malmö               ... avg=23.23ms  med=23.10ms  σ=0.26ms  (3/3 probes)
  [41/66] VPN51   185.157.163.12   Malmö               ... avg=41.87ms  med=49.20ms  σ=12.85ms  (3/3 probes)
  [42/66] VPN52   185.157.163.13   Malmö               ... avg=24.20ms  med=23.80ms  σ=0.86ms  (3/3 probes)
  [43/66] VPN53   185.157.163.14   Malmö               ... avg=39.87ms  med=24.40ms  σ=22.44ms  (3/3 probes)
  [44/66] VPN54   185.157.163.15   Malmö               ... avg=36.80ms  med=24.50ms  σ=17.75ms  (3/3 probes)
  [45/66] VPN55   185.157.163.16   Malmö               ... avg=26.00ms  med=23.90ms  σ=3.40ms  (3/3 probes)
  [46/66] VPN56   185.157.163.17   Malmö               ... avg=34.70ms  med=26.50ms  σ=13.40ms  (3/3 probes)
  [47/66] VPN34   45.134.142.3     Miami                ... avg=164.67ms  med=129.00ms  σ=52.58ms  (3/3 probes)
  [48/66] VPN100  94.198.96.163    Milan                ... avg=40.47ms  med=40.30ms  σ=0.39ms  (3/3 probes)
  [49/66] VPN101  138.199.38.149   Offenbach            ... avg=19.33ms  med=16.30ms  σ=4.43ms  (3/3 probes)
  [50/66] VPN92   138.199.38.131   Offenbach            ... avg=32.23ms  med=31.80ms  σ=7.97ms  (3/3 probes)
  [51/66] VPN37   45.148.18.35     Oslo                 ... avg=53.40ms  med=62.00ms  σ=12.23ms  (3/3 probes)
  [52/66] VPN38   45.148.18.36     Oslo                 ... avg=48.03ms  med=37.00ms  σ=16.24ms  (3/3 probes)
  [53/66] VPN39   45.148.18.37     Oslo                 ... avg=45.50ms  med=37.00ms  σ=12.95ms  (3/3 probes)
  [54/66] VPN69   139.28.219.35    Paris                ... avg=29.93ms  med=21.70ms  σ=12.00ms  (3/3 probes)
  [55/66] VPN102  156.146.51.227   Seattle              ... avg=160.00ms  med=160.00ms  σ=0.00ms  (3/3 probes)
  [56/66] VPN77   37.120.208.35    Singapore            ... avg=265.00ms  med=261.00ms  σ=5.66ms  (3/3 probes)
  [57/66] VPN85   45.148.17.36     Sundsvall            ... avg=47.27ms  med=39.50ms  σ=13.91ms  (3/3 probes)
  [58/66] VPN86   45.148.17.37     Sundsvall            ... avg=80.80ms  med=66.90ms  σ=19.94ms  (3/3 probes)
  [59/66] VPN87   45.148.17.38     Sundsvall            ... avg=48.10ms  med=39.30ms  σ=14.54ms  (3/3 probes)
  [60/66] VPN88   45.148.17.39     Sundsvall            ... avg=46.67ms  med=42.10ms  σ=11.06ms  (3/3 probes)
  [61/66] VPN26   217.138.204.35   Sydney               ... avg=368.33ms  med=373.00ms  σ=7.32ms  (3/3 probes)
  [62/66] VPN89   217.138.212.51   Tokyo                ... avg=261.00ms  med=265.00ms  σ=7.87ms  (3/3 probes)
  [63/66] VPN25   104.245.145.244  Toronto              ... avg=120.00ms  med=120.00ms  σ=1.63ms  (3/3 probes)
  [64/66] VPN44   37.120.212.227   Vienna               ... avg=35.40ms  med=40.10ms  σ=7.58ms  (3/3 probes)
  [65/66] VPN95   138.199.59.3     Warsaw               ... avg=36.40ms  med=35.70ms  σ=1.21ms  (3/3 probes)
  [66/66] VPN43   152.89.162.4     Zurich               ... avg=31.27ms  med=31.00ms  σ=0.60ms  (3/3 probes)


Results: 66 reachable, 0 timeout(s) [method: icmp]

Rank    Server   IP                City             avg(ICMP)    median       σ   BW(Mbit)   Load%
────────────────────────────────────────────────────────────────────────────────────────────────────
#1      VPN101   138.199.38.149    Offenbach            19.33     16.30     4.43        489     16%
#2      VPN41    185.157.163.8     Malmö               22.63     22.60     0.21        484     32%
#3      VPN40    185.157.163.7     Malmö               23.20     23.20     0.08        109      7%
#4      VPN50    185.157.163.11    Malmö               23.23     23.10     0.26         69      5%
#5      VPN52    185.157.163.13    Malmö               24.20     23.80     0.86        216     14%
#6      VPN30    185.157.162.8     Amsterdam            24.27     15.10    13.25         71      7%
#7      VPN29    185.157.162.7     Amsterdam            25.43     15.80    15.06        161     16%
#8      VPN55    185.157.163.16    Malmö               26.00     23.90     3.40         88      6%
#9      VPN01    185.157.163.3     Malmö               26.87     25.30     3.41         99      7%
#10     VPN98    45.141.152.68     Frankfurt            27.87     23.50     6.46        292     10%
#11     VPN90    84.19.175.164     Erfurt               27.97     21.60    10.08        206     21%
#12     VPN80    193.187.91.203    Gothenburg           28.23     28.10     0.19        353     12%
#13     VPN68    89.238.176.3      London               28.67     18.30    14.66        171      6%
#14     VPN67    193.187.91.200    Gothenburg           29.77     28.40     2.00        359     12%
#15     VPN69    139.28.219.35     Paris                29.93     21.70    12.00        142     14%
#16     VPN43    152.89.162.4      Zurich               31.27     31.00     0.60        971     32%
#17     VPN93    217.114.215.130   Erfurt               31.73     23.40    12.21        223     22%
#18     VPN92    138.199.38.131    Offenbach            32.23     31.80     7.97        222      7%
#19     VPN36    185.157.162.10    Amsterdam            33.10     42.10    13.80         89      9%
#20     VPN42    185.157.163.9     Malmö               33.17     23.20    14.17        374     25%
#21     VPN06    185.157.163.4     Malmö               34.47     23.70    15.30        136      9%
#22     VPN08    185.157.163.6     Malmö               34.60     23.30    16.62        108      7%
#23     VPN56    185.157.163.17    Malmö               34.70     26.50    13.40        233     16%
#24     VPN44    37.120.212.227    Vienna               35.40     40.10     7.58        230      8%
#25     VPN07    185.157.163.5     Malmö               35.80     30.10    10.78         91      6%
#26     VPN95    138.199.59.3      Warsaw               36.40     35.70     1.21        289     29%
#27     VPN54    185.157.163.15    Malmö               36.80     24.50    17.75        624     42%
#28     VPN65    193.187.91.198    Gothenburg           37.70     29.10    12.52        369     12%
#29     VPN28    185.157.162.6     Amsterdam            37.77     40.90    17.05        167     17%
#30     VPN66    193.187.91.199    Gothenburg           39.33     28.30    15.67        927     31%
#31     VPN53    185.157.163.14    Malmö               39.87     24.40    22.44        113      8%
#32     VPN100   94.198.96.163     Milan                40.47     40.30     0.39        224     22%
#33     VPN83    193.187.91.206    Gothenburg           40.50     25.60    21.21        324     11%
#34     VPN94    45.141.152.69     Frankfurt            40.53     23.40    24.30        259      9%
#35     VPN51    185.157.163.12    Malmö               41.87     49.20    12.85         71      5%
#36     VPN39    45.148.18.37      Oslo                 45.50     37.00    12.95        190     19%
#37     VPN82    193.187.91.205    Gothenburg           45.87     55.80    14.26        177      6%
#38     VPN79    193.187.91.202    Gothenburg           46.47     54.50    12.95        253      8%
#39     VPN88    45.148.17.39      Sundsvall            46.67     42.10    11.06        904     60%
#40     VPN85    45.148.17.36      Sundsvall            47.27     39.50    13.91        186     12%
#41     VPN38    45.148.18.36      Oslo                 48.03     37.00    16.24        166     17%
#42     VPN87    45.148.17.38      Sundsvall            48.10     39.30    14.54        330     22%
#43     VPN57    193.187.91.195    Gothenburg           49.43     58.60    14.62       1184     39%
#44     VPN104   46.246.34.51      Helsinki             49.57     45.00     6.53        670     22%
#45     VPN78    193.187.91.201    Gothenburg           50.07     60.20    14.83        220      7%
#46     VPN37    45.148.18.35      Oslo                 53.40     62.00    12.23         81      8%
#47     VPN27    185.236.203.98    Copenhagen           54.47     55.40     6.48        407     14%
#48     VPN96    143.244.46.147    Kyiv                 56.43     60.10     9.58        159     16%
#49     VPN59    193.187.91.197    Gothenburg           57.80     58.20     0.64        331     11%
#50     VPN58    193.187.91.196    Gothenburg           74.60     29.00    64.63        564     19%
#51     VPN35    185.157.162.9     Amsterdam            79.07     13.20    93.29        724     72%
#52     VPN86    45.148.17.37      Sundsvall            80.80     66.90    19.94        168     11%
#53     VPN81    193.187.91.204    Gothenburg           87.20     56.80    61.52        582     19%
#54     VPN49    185.157.163.10    Malmö               87.63     54.80    69.28        140      9%
#55     VPN45    37.120.206.163    Bucharest            94.77     52.10    67.46        579     58%
#56     VPN25    104.245.145.244   Toronto             120.00    120.00     1.63        549     18%
#57     VPN18    45.134.140.67     Atlanta             121.67    115.00     9.43        538     18%
#58     VPN70    192.145.124.3     Madrid              124.27     67.30    97.88        236     24%
#59     VPN32    87.249.134.67     Chicago             128.00    134.00     8.49        399     13%
#60     VPN103   169.150.231.243   Denver              147.67    137.00    15.08        172      6%
#61     VPN102   156.146.51.227    Seattle             160.00    160.00     0.00        292     10%
#62     VPN34    45.134.142.3      Miami               164.67    129.00    52.58         81      8%
#63     VPN33    194.37.97.35      Dallas              172.33    163.00    14.64        416     14%
#64     VPN89    217.138.212.51    Tokyo               261.00    265.00     7.87        306     31%
#65     VPN77    37.120.208.35     Singapore           265.00    261.00     5.66         16      2%
#66     VPN26    217.138.204.35    Sydney              368.33    373.00     7.32         70      7%
```
</details>

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
**Requirements:** PHP 8.x with `ext-curl`, `ext-intl`, `ext-dom`, `ping` and `dig` installed.

## License

MIT
