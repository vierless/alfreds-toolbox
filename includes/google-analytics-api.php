<?php
class GoogleAnalyticsAPI {
    private $credentials_path;
    private $property_id;
    private $cache_duration = [
        'today' => 900,         // 15 Minuten für "Heute" (häufigere Updates)
        'yesterday' => 86400,   // 24h für "Gestern" (statisch)
        'last7days' => 3600,    // 1h für "Letzte 7 Tage"
        'last30days' => 7200,   // 2h für "Letzte 30 Tage"
        'thisMonth' => 3600,    // 1h für "Dieser Monat"
        'lastMonth' => 86400    // 24h für "Letzter Monat" (statisch)
    ];
    
    public function __construct($property_id) {
        $this->property_id = $property_id;
        require_once plugin_dir_path(__FILE__) . 'services/google-credentials.php';
        $this->credentials = get_google_credentials();
    }

    public function clear_cache() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ga_data_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ga_data_%'");
    }

    public function validate_property() {
        try {
            $access_token = $this->get_access_token();
            
            $response = wp_remote_post('https://analyticsdata.googleapis.com/v1beta/properties/' . $this->property_id . ':runReport', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'dateRanges' => [
                        [
                            'startDate' => 'yesterday',
                            'endDate' => 'yesterday'
                        ]
                    ],
                    'metrics' => [
                        ['name' => 'activeUsers']
                    ]
                ])
            ]);
    
            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }
    
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $response_code = wp_remote_retrieve_response_code($response);
    
            if ($response_code === 403) {
                return 'permission_denied';
            } elseif ($response_code === 400 || $response_code === 404) {
                return 'invalid_property';
            } elseif ($response_code !== 200) {
                return 'error';
            }
    
            return 'success';
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'PERMISSION_DENIED') !== false) {
                return 'permission_denied';
            } elseif (strpos($e->getMessage(), 'INVALID_ARGUMENT') !== false) {
                return 'invalid_property';
            }
            return 'error';
        }
    }
    
    public function get_analytics_data($date_range = 'last30days') {
        $cache_key = 'ga_data_' . $this->property_id . '_' . $date_range;
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            // Debug-Daten zum Cache hinzufügen
            $cached['debug'] = [
                'date_range' => $date_range,
                'dates' => $this->get_date_range($date_range),
                'is_cached' => true,
                'cache_key' => $cache_key
            ];
            return $cached;
        }
        
        try {
            $data = $this->fetch_from_api($date_range);
            $formatted_data = $this->format_analytics_data($data);
            
            $formatted_data['debug'] = [
                'date_range' => $date_range,
                'dates' => $this->get_date_range($date_range),
                'is_cached' => false,
                'cache_key' => $cache_key
            ];
            
            $duration = $this->cache_duration[$date_range] ?? 7200;
            set_transient($cache_key, $formatted_data, $duration);
            
            return $formatted_data;
        } catch (Exception $e) {
            error_log('GA API Error: ' . $e->getMessage());
            
            if (strpos($e->getMessage(), 'PERMISSION_DENIED') !== false) {
                return ['error' => 'permission_denied'];
            } elseif (strpos($e->getMessage(), 'INVALID_ARGUMENT') !== false) {
                return ['error' => 'invalid_property'];
            }
            
            return ['error' => 'api_error', 'message' => $e->getMessage()];
        }
    }

    private function fetch_from_api($date_range, $retry = 0) {
        try {
            $access_token = $this->get_access_token();
            $dates = $this->get_date_range($date_range);
            
            $overview_response = wp_remote_post('https://analyticsdata.googleapis.com/v1beta/properties/' . $this->property_id . ':runReport', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'dateRanges' => [
                        [
                            'startDate' => $dates['start'],
                            'endDate' => $dates['end']
                        ]
                    ],
                    'metrics' => [
                        ['name' => 'activeUsers'],
                        ['name' => 'screenPageViews'],
                        ['name' => 'userEngagementDuration'],
                        ['name' => 'bounceRate'],
                        ['name' => 'engagementRate']
                    ]
                ])
            ]);

            $pages_response = wp_remote_post('https://analyticsdata.googleapis.com/v1beta/properties/' . $this->property_id . ':runReport', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'dateRanges' => [
                        [
                            'startDate' => $dates['start'],
                            'endDate' => $dates['end']
                        ]
                    ],
                    'metrics' => [
                        ['name' => 'screenPageViews']
                    ],
                    'dimensions' => [
                        ['name' => 'pagePath']
                    ],
                    'orderBys' => [
                        [
                            'metric' => ['metricName' => 'screenPageViews'],
                            'desc' => true
                        ]
                    ]
                ])
            ]);

            $details_response = wp_remote_post('https://analyticsdata.googleapis.com/v1beta/properties/' . $this->property_id . ':runReport', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'dateRanges' => [
                        [
                            'startDate' => $dates['start'],
                            'endDate' => $dates['end']
                        ]
                    ],
                    'dimensions' => [
                        ['name' => 'country'],
                    ],
                    'metrics' => [
                        ['name' => 'activeUsers']
                    ],
                    'orderBys' => [
                        [
                            'metric' => ['metricName' => 'activeUsers'],
                            'desc' => true
                        ]
                    ],
                    'limit' => 5
                ])
            ]);

            $browsers_response = wp_remote_post('https://analyticsdata.googleapis.com/v1beta/properties/' . $this->property_id . ':runReport', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'dateRanges' => [['startDate' => $dates['start'], 'endDate' => $dates['end']]],
                    'dimensions' => [
                        ['name' => 'browser']
                    ],
                    'metrics' => [
                        ['name' => 'activeUsers']
                    ],
                    'orderBys' => [
                        [
                            'metric' => ['metricName' => 'activeUsers'],
                            'desc' => true
                        ]
                    ],
                    'limit' => 5
                ])
            ]);

            $devices_response = wp_remote_post('https://analyticsdata.googleapis.com/v1beta/properties/' . $this->property_id . ':runReport', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'dateRanges' => [['startDate' => $dates['start'], 'endDate' => $dates['end']]],
                    'dimensions' => [
                        ['name' => 'deviceCategory']
                    ],
                    'metrics' => [
                        ['name' => 'activeUsers']
                    ],
                    'orderBys' => [
                        [
                            'metric' => ['metricName' => 'activeUsers'],
                            'desc' => true
                        ]
                    ]
                ])
            ]);

            return [
                'overview' => json_decode(wp_remote_retrieve_body($overview_response), true),
                'pages' => json_decode(wp_remote_retrieve_body($pages_response), true),
                'countries' => json_decode(wp_remote_retrieve_body($details_response), true),
                'browsers' => json_decode(wp_remote_retrieve_body($browsers_response), true),
                'devices' => json_decode(wp_remote_retrieve_body($devices_response), true)
            ];
        } catch (Exception $e) {
            error_log('GA API Error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function validate_against_ga($date_range = 'last30days') {
        $api_data = $this->get_analytics_data($date_range);
        
        return [
            'raw_api_data' => $api_data,
            'formatted_metrics' => [
                'visitors' => number_format($api_data['overview']['visitors']['current']),
                'pageviews' => number_format($api_data['overview']['pageviews']['current']),
                'avg_duration' => $api_data['overview']['avgDuration']['current'],
                'bounce_rate' => number_format($api_data['overview']['bounceRate']['current'], 2) . '%',
                'engagement_rate' => number_format($api_data['overview']['engagementRate']['current'], 2) . '%'
            ]
        ];
    }

    private function format_analytics_data($data) {
        $overview_metrics = $data['overview']['rows'][0]['metricValues'] ?? [];
        $total_users = isset($overview_metrics[0]['value']) ? (int) $overview_metrics[0]['value'] : 0;
        
        $formatted = [
            'overview' => [
                'visitors' => [
                    'current' => isset($overview_metrics[0]['value']) ? (int) $overview_metrics[0]['value'] : 0,
                    'label' => 'Besucher'
                ],
                'pageviews' => [
                    'current' => isset($overview_metrics[1]['value']) ? (int) $overview_metrics[1]['value'] : 0,
                    'label' => 'Seitenaufrufe'
                ],
                'avgDuration' => [
                    'current' => isset($overview_metrics[2]['value']) && isset($overview_metrics[0]['value']) 
                        ? $this->format_duration((float)$overview_metrics[2]['value'] / (float)$overview_metrics[0]['value']) 
                        : '0:00',
                    'label' => 'Durchschn. Dauer'
                ],
                'bounceRate' => [
                    'current' => isset($overview_metrics[3]['value']) ? round((float) $overview_metrics[3]['value'] * 100, 2) : 0,
                    'label' => 'Absprungrate'
                ],
                'engagementRate' => [
                    'current' => isset($overview_metrics[4]['value']) ? round((float) $overview_metrics[4]['value'] * 100, 2) : 0,
                    'label' => 'Engagement Rate'
                ]
            ]
        ];
    
        // Pages verarbeiten
        $pages = [];
        $pages_rows = $data['pages']['rows'] ?? [];
        foreach ($pages_rows as $row) {
            $path = $row['dimensionValues'][0]['value'] ?? '';
            $views = isset($row['metricValues'][0]['value']) ? (int) $row['metricValues'][0]['value'] : 0;
            
            $normalized_path = $this->normalize_path($path);
            if (!isset($pages[$normalized_path])) {
                $pages[$normalized_path] = ['path' => $normalized_path, 'views' => $views];
            }
        }
        
        // Sortieren und Top 5 auswählen
        uasort($pages, fn($a, $b) => $b['views'] <=> $a['views']);
        $formatted['topPages'] = array_slice(array_values($pages), 0, 5);
    
        // Länder verarbeiten
        $countries = [];
        $countries_data = $data['countries']['rows'] ?? [];
        foreach ($countries_data as $row) {
            $english_name = $row['dimensionValues'][0]['value'] ?? '';
            $users = isset($row['metricValues'][0]['value']) ? (int) $row['metricValues'][0]['value'] : 0;
            
            if ($english_name && $users > 0) {
                $country_code = $this->get_country_code_from_name($english_name);
                
                if ($country_code !== 'unknown') {
                    $flag_svg = $this->get_flag_svg($country_code);
                    
                    $countries[$country_code] = [
                        'name' => $this->get_country_name($country_code),
                        'users' => $users,
                        'percent' => $total_users > 0 ? round(($users / $total_users) * 100, 1) : 0,
                        'code' => strtolower($country_code),
                        'flagSvg' => $flag_svg // SVG wird jetzt korrekt als String übertragen
                    ];
                }
            }
        }
        // Nach Nutzeranzahl sortieren
        uasort($countries, function($a, $b) {
            return $b['users'] <=> $a['users'];
        });

        $formatted['countries'] = $countries;
    
        // Browser verarbeiten
        $browsers = [];
        $browsers_data = $data['browsers']['rows'] ?? [];
        foreach ($browsers_data as $row) {
            $browser = $row['dimensionValues'][0]['value'] ?? '';
            $users = isset($row['metricValues'][0]['value']) ? (int) $row['metricValues'][0]['value'] : 0;
            
            if ($browser && $users > 0) {
                $browsers[$browser] = [
                    'name' => $browser,
                    'users' => $users,
                    'percent' => $total_users > 0 ? round(($users / $total_users) * 100, 1) : 0,
                    'browserSvg' => $this->get_browser_svg($browser)
                ];
            }
        }

        $formatted['browsers'] = $browsers;
    
        // Geräte verarbeiten
        $devices = [];
        $devices_data = $data['devices']['rows'] ?? [];
        foreach ($devices_data as $row) {
            $device = ucfirst($row['dimensionValues'][0]['value'] ?? '');
            $users = isset($row['metricValues'][0]['value']) ? (int) $row['metricValues'][0]['value'] : 0;
            if ($device) {
                $devices[$device] = [
                    'users' => $users,
                    'percent' => round(($users / $total_users) * 100, 1)
                ];
            }
        }
        $formatted['devices'] = $devices;
    
        return $formatted;
    }

    private function normalize_path($path) {
        $path = strtok($path, '?');
        $path = rtrim($path, '/');

        if (empty($path)) {
            $path = '/';
        }

        return $path;
    }

    private function get_country_name($code) {
        $countries = [
            'AF' => 'Afghanistan',
            'AL' => 'Albanien',
            'DZ' => 'Algerien',
            'AS' => 'Amerikanisch-Samoa',
            'AD' => 'Andorra',
            'AO' => 'Angola',
            'AI' => 'Anguilla',
            'AQ' => 'Antarktis',
            'AG' => 'Antigua und Barbuda',
            'AR' => 'Argentinien',
            'AM' => 'Armenien',
            'AW' => 'Aruba',
            'AU' => 'Australien',
            'AT' => 'Österreich',
            'AZ' => 'Aserbaidschan',
            'BS' => 'Bahamas',
            'BH' => 'Bahrain',
            'BD' => 'Bangladesch',
            'BB' => 'Barbados',
            'BY' => 'Weißrussland',
            'BE' => 'Belgien',
            'BZ' => 'Belize',
            'BJ' => 'Benin',
            'BM' => 'Bermuda',
            'BT' => 'Bhutan',
            'BO' => 'Bolivien',
            'BQ' => 'Bonaire, Sint Eustatius und Saba',
            'BA' => 'Bosnien und Herzegowina',
            'BW' => 'Botswana',
            'BR' => 'Brasilien',
            'IO' => 'Britisches Territorium im Indischen Ozean',
            'BN' => 'Brunei Darussalam',
            'BG' => 'Bulgarien',
            'BF' => 'Burkina Faso',
            'BI' => 'Burundi',
            'KH' => 'Kambodscha',
            'CM' => 'Kamerun',
            'CA' => 'Kanada',
            'CV' => 'Kap Verde',
            'KY' => 'Kaimaninseln',
            'CF' => 'Zentralafrikanische Republik',
            'TD' => 'Tschad',
            'CL' => 'Chile',
            'CN' => 'China',
            'CX' => 'Weihnachtsinsel',
            'CC' => 'Cocos (Keeling)-Inseln',
            'CO' => 'Kolumbien',
            'KM' => 'Komoren',
            'CG' => 'Kongo',
            'CD' => 'Demokratische Republik Kongo',
            'CK' => 'Cookinseln',
            'CR' => 'Costa Rica',
            'HR' => 'Kroatien',
            'CU' => 'Kuba',
            'CW' => 'Curacao',
            'CY' => 'Zypern',
            'CZ' => 'Tschechien',
            'DK' => 'Dänemark',
            'DJ' => 'Dschibuti',
            'DM' => 'Dominica',
            'DO' => 'Dominikanische Republik',
            'EC' => 'Ecuador',
            'EG' => 'Ägypten',
            'SV' => 'El Salvador',
            'GQ' => 'Äquatorialguinea',
            'ER' => 'Eritrea',
            'EE' => 'Estland',
            'SZ' => 'Eswatini',
            'ET' => 'Äthiopien',
            'FK' => 'Falklandinseln',
            'FO' => 'Färöer',
            'FJ' => 'Fidschi',
            'FI' => 'Finnland',
            'FR' => 'Frankreich',
            'GF' => 'Französisch-Guayana',
            'PF' => 'Französisch-Polynesien',
            'TF' => 'Französische Süd- und Antarktisgebiete',
            'GA' => 'Gabun',
            'GM' => 'Gambia',
            'GE' => 'Georgien',
            'DE' => 'Deutschland',
            'GH' => 'Ghana',
            'GI' => 'Gibraltar',
            'GR' => 'Griechenland',
            'GL' => 'Grönland',
            'GD' => 'Grenada',
            'GP' => 'Guadeloupe',
            'GU' => 'Guam',
            'GT' => 'Guatemala',
            'GG' => 'Guernsey',
            'GN' => 'Guinea',
            'GW' => 'Guinea-Bissau',
            'GY' => 'Guyana',
            'HT' => 'Haiti',
            'HM' => 'Heard- und McDonald-Inseln',
            'HN' => 'Honduras',
            'HK' => 'Hongkong',
            'HU' => 'Ungarn',
            'IS' => 'Island',
            'IN' => 'Indien',
            'ID' => 'Indonesien',
            'IR' => 'Iran',
            'IQ' => 'Irak',
            'IE' => 'Irland',
            'IL' => 'Israel',
            'IT' => 'Italien',
            'JM' => 'Jamaika',
            'JP' => 'Japan',
            'JE' => 'Jersey',
            'JO' => 'Jordanien',
            'KZ' => 'Kasachstan',
            'KE' => 'Kenia',
            'KI' => 'Kiribati',
            'KP' => 'Nordkorea',
            'KR' => 'Südkorea',
            'KW' => 'Kuwait',
            'KG' => 'Kirgistan',
            'LA' => 'Laos',
            'LV' => 'Lettland',
            'LB' => 'Libanon',
            'LS' => 'Lesotho',
            'LR' => 'Liberia',
            'LY' => 'Libyen',
            'LI' => 'Liechtenstein',
            'LT' => 'Litauen',
            'LU' => 'Luxemburg',
            'MO' => 'Macau',
            'MK' => 'Nordmazedonien',
            'MG' => 'Madagaskar',
            'MW' => 'Malawi',
            'MY' => 'Malaysia',
            'MV' => 'Malediven',
            'ML' => 'Mali',
            'MT' => 'Malta',
            'MH' => 'Marshallinseln',
            'MQ' => 'Martinique',
            'MR' => 'Mauritanien',
            'MU' => 'Mauritius',
            'YT' => 'Mayotte',
            'MX' => 'Mexiko',
            'FM' => 'Mikronesien',
            'MD' => 'Moldawien',
            'MC' => 'Monaco',
            'MN' => 'Mongolei',
            'ME' => 'Montenegro',
            'MS' => 'Montserrat',
            'MA' => 'Marokko',
            'MZ' => 'Mosambik',
            'MM' => 'Myanmar',
            'NA' => 'Namibia',
            'NR' => 'Nauru',
            'NP' => 'Nepal',
            'NL' => 'Niederlande',
            'NC' => 'Neukaledonien',
            'NZ' => 'Neuseeland',
            'NI' => 'Nicaragua',
            'NE' => 'Niger',
            'NG' => 'Nigeria',
            'NU' => 'Niue',
            'NF' => 'Norfolkinsel',
            'MP' => 'Nördliche Marianen',
            'NO' => 'Norwegen',
            'OM' => 'Oman',
            'PK' => 'Pakistan',
            'PW' => 'Palau',
            'PS' => 'Palästinensische Gebiete',
            'PA' => 'Panama',
            'PG' => 'Papua-Neuguinea',
            'PY' => 'Paraguay',
            'PE' => 'Peru',
            'PH' => 'Philippinen',
            'PN' => 'Pitcairninseln',
            'PL' => 'Polen',
            'PT' => 'Portugal',
            'PR' => 'Puerto Rico',
            'QA' => 'Katar',
            'RE' => 'Réunion',
            'RO' => 'Rumänien',
            'RU' => 'Russland',
            'RW' => 'Ruanda',
            'BL' => 'Saint-Barthélemy',
            'SH' => 'St. Helena',
            'KN' => 'St. Kitts und Nevis',
            'LC' => 'St. Lucia',
            'MF' => 'Saint-Martin (französisch)',
            'PM' => 'Saint-Pierre und Miquelon',
            'VC' => 'St. Vincent und die Grenadinen',
            'WS' => 'Samoa',
            'SM' => 'San Marino',
            'ST' => 'São Tomé und Príncipe',
            'SA' => 'Saudi-Arabien',
            'SN' => 'Senegal',
            'RS' => 'Serbien',
            'SC' => 'Seychellen',
            'SL' => 'Sierra Leone',
            'SG' => 'Singapur',
            'SX' => 'Sint Maarten (niederländischer Teil)',
            'SK' => 'Slowakei',
            'SI' => 'Slowenien',
            'SB' => 'Salomonen',
            'SO' => 'Somalia',
            'ZA' => 'Südafrika',
            'GS' => 'Südgeorgien und die Südlichen Sandwichinseln',
            'SS' => 'Südsudan',
            'ES' => 'Spanien',
            'LK' => 'Sri Lanka',
            'SD' => 'Sudan',
            'SR' => 'Surinam',
            'SJ' => 'Svalbard und Jan Mayen',
            'SE' => 'Schweden',
            'CH' => 'Schweiz',
            'SY' => 'Syrien',
            'TW' => 'Taiwan',
            'TJ' => 'Tadschikistan',
            'TZ' => 'Tansania',
            'TH' => 'Thailand',
            'TL' => 'Osttimor',
            'TG' => 'Togo',
            'TK' => 'Tokelau',
            'TO' => 'Tonga',
            'TT' => 'Trinidad und Tobago',
            'TN' => 'Tunesien',
            'TR' => 'Türkei',
            'TM' => 'Turkmenistan',
            'TC' => 'Turks- und Caicosinseln',
            'TV' => 'Tuvalu',
            'UG' => 'Uganda',
            'UA' => 'Ukraine',
            'AE' => 'Vereinigte Arabische Emirate',
            'GB' => 'Vereinigtes Königreich',
            'UM' => 'USA - Außengebiete',
            'US' => 'USA',
            'UY' => 'Uruguay',
            'UZ' => 'Usbekistan',
            'VU' => 'Vanuatu',
            'VA' => 'Vatikanstadt',
            'VE' => 'Venezuela',
            'VN' => 'Vietnam',
            'WF' => 'Wallis und Futuna',
            'EH' => 'Westsahara',
            'YE' => 'Jemen',
            'ZM' => 'Sambia',
            'ZW' => 'Simbabwe'
        ];        
        
        return $countries[$code] ?? $code;
    }

    private function get_country_code_from_name($english_name) {
        $country_mapping = [
            'Afghanistan' => 'AF',
            'Albania' => 'AL',
            'Algeria' => 'DZ',
            'American Samoa' => 'AS',
            'Andorra' => 'AD',
            'Angola' => 'AO',
            'Anguilla' => 'AI',
            'Antarctica' => 'AQ',
            'Antigua and Barbuda' => 'AG',
            'Argentina' => 'AR',
            'Armenia' => 'AM',
            'Aruba' => 'AW',
            'Australia' => 'AU',
            'Austria' => 'AT',
            'Azerbaijan' => 'AZ',
            'Bahamas' => 'BS',
            'Bahrain' => 'BH',
            'Bangladesh' => 'BD',
            'Barbados' => 'BB',
            'Belarus' => 'BY',
            'Belgium' => 'BE',
            'Belize' => 'BZ',
            'Benin' => 'BJ',
            'Bermuda' => 'BM',
            'Bhutan' => 'BT',
            'Bolivia' => 'BO',
            'Bonaire, Sint Eustatius and Saba' => 'BQ',
            'Bosnia and Herzegovina' => 'BA',
            'Botswana' => 'BW',
            'Brazil' => 'BR',
            'British Indian Ocean Territory' => 'IO',
            'Brunei Darussalam' => 'BN',
            'Bulgaria' => 'BG',
            'Burkina Faso' => 'BF',
            'Burundi' => 'BI',
            'Cambodia' => 'KH',
            'Cameroon' => 'CM',
            'Canada' => 'CA',
            'Cape Verde' => 'CV',
            'Cayman Islands' => 'KY',
            'Central African Republic' => 'CF',
            'Chad' => 'TD',
            'Chile' => 'CL',
            'China' => 'CN',
            'Christmas Island' => 'CX',
            'Cocos (Keeling) Islands' => 'CC',
            'Colombia' => 'CO',
            'Comoros' => 'KM',
            'Congo' => 'CG',
            'Congo, Democratic Republic of the' => 'CD',
            'Cook Islands' => 'CK',
            'Costa Rica' => 'CR',
            'Croatia' => 'HR',
            'Cuba' => 'CU',
            'Curacao' => 'CW',
            'Cyprus' => 'CY',
            'Czech Republic' => 'CZ',
            'Denmark' => 'DK',
            'Djibouti' => 'DJ',
            'Dominica' => 'DM',
            'Dominican Republic' => 'DO',
            'Ecuador' => 'EC',
            'Egypt' => 'EG',
            'El Salvador' => 'SV',
            'Equatorial Guinea' => 'GQ',
            'Eritrea' => 'ER',
            'Estonia' => 'EE',
            'Eswatini' => 'SZ',
            'Ethiopia' => 'ET',
            'Falkland Islands' => 'FK',
            'Faroe Islands' => 'FO',
            'Fiji' => 'FJ',
            'Finland' => 'FI',
            'France' => 'FR',
            'French Guiana' => 'GF',
            'French Polynesia' => 'PF',
            'French Southern and Antarctic Lands' => 'TF',
            'Gabon' => 'GA',
            'Gambia' => 'GM',
            'Georgia' => 'GE',
            'Germany' => 'DE',
            'Ghana' => 'GH',
            'Gibraltar' => 'GI',
            'Greece' => 'GR',
            'Greenland' => 'GL',
            'Grenada' => 'GD',
            'Guadeloupe' => 'GP',
            'Guam' => 'GU',
            'Guatemala' => 'GT',
            'Guernsey' => 'GG',
            'Guinea' => 'GN',
            'Guinea-Bissau' => 'GW',
            'Guyana' => 'GY',
            'Haiti' => 'HT',
            'Heard Island and McDonald Islands' => 'HM',
            'Honduras' => 'HN',
            'Hong Kong' => 'HK',
            'Hungary' => 'HU',
            'Iceland' => 'IS',
            'India' => 'IN',
            'Indonesia' => 'ID',
            'Iran' => 'IR',
            'Iraq' => 'IQ',
            'Ireland' => 'IE',
            'Israel' => 'IL',
            'Italy' => 'IT',
            'Jamaica' => 'JM',
            'Japan' => 'JP',
            'Jersey' => 'JE',
            'Jordan' => 'JO',
            'Kazakhstan' => 'KZ',
            'Kenya' => 'KE',
            'Kiribati' => 'KI',
            'Korea, Democratic People\'s Republic of' => 'KP',
            'Korea, Republic of' => 'KR',
            'Kuwait' => 'KW',
            'Kyrgyzstan' => 'KG',
            'Laos' => 'LA',
            'Latvia' => 'LV',
            'Lebanon' => 'LB',
            'Lesotho' => 'LS',
            'Liberia' => 'LR',
            'Libya' => 'LY',
            'Liechtenstein' => 'LI',
            'Lithuania' => 'LT',
            'Luxembourg' => 'LU',
            'Macao' => 'MO',
            'North Macedonia' => 'MK',
            'Madagascar' => 'MG',
            'Malawi' => 'MW',
            'Malaysia' => 'MY',
            'Maldives' => 'MV',
            'Mali' => 'ML',
            'Malta' => 'MT',
            'Marshall Islands' => 'MH',
            'Martinique' => 'MQ',
            'Mauritania' => 'MR',
            'Mauritius' => 'MU',
            'Mayotte' => 'YT',
            'Mexico' => 'MX',
            'Micronesia (Federated States of)' => 'FM',
            'Moldova' => 'MD',
            'Monaco' => 'MC',
            'Mongolia' => 'MN',
            'Montenegro' => 'ME',
            'Montserrat' => 'MS',
            'Morocco' => 'MA',
            'Mozambique' => 'MZ',
            'Myanmar' => 'MM',
            'Namibia' => 'NA',
            'Nauru' => 'NR',
            'Nepal' => 'NP',
            'Netherlands' => 'NL',
            'New Caledonia' => 'NC',
            'New Zealand' => 'NZ',
            'Nicaragua' => 'NI',
            'Niger' => 'NE',
            'Nigeria' => 'NG',
            'Niue' => 'NU',
            'Norfolk Island' => 'NF',
            'Northern Mariana Islands' => 'MP',
            'Norway' => 'NO',
            'Oman' => 'OM',
            'Pakistan' => 'PK',
            'Palau' => 'PW',
            'Palestinian Territories' => 'PS',
            'Panama' => 'PA',
            'Papua New Guinea' => 'PG',
            'Paraguay' => 'PY',
            'Peru' => 'PE',
            'Philippines' => 'PH',
            'Pitcairn Islands' => 'PN',
            'Poland' => 'PL',
            'Portugal' => 'PT',
            'Puerto Rico' => 'PR',
            'Qatar' => 'QA',
            'Réunion' => 'RE',
            'Romania' => 'RO',
            'Russia' => 'RU',
            'Rwanda' => 'RW',
            'Saint Barthélemy' => 'BL',
            'Saint Helena' => 'SH',
            'Saint Kitts and Nevis' => 'KN',
            'Saint Lucia' => 'LC',
            'Saint Martin (French part)' => 'MF',
            'Saint Pierre and Miquelon' => 'PM',
            'Saint Vincent and the Grenadines' => 'VC',
            'Samoa' => 'WS',
            'San Marino' => 'SM',
            'São Tomé and Príncipe' => 'ST',
            'Saudi Arabia' => 'SA',
            'Senegal' => 'SN',
            'Serbia' => 'RS',
            'Seychelles' => 'SC',
            'Sierra Leone' => 'SL',
            'Singapore' => 'SG',
            'Sint Maarten (Dutch part)' => 'SX',
            'Slovakia' => 'SK',
            'Slovenia' => 'SI',
            'Solomon Islands' => 'SB',
            'Somalia' => 'SO',
            'South Africa' => 'ZA',
            'South Georgia and the South Sandwich Islands' => 'GS',
            'South Sudan' => 'SS',
            'Spain' => 'ES',
            'Sri Lanka' => 'LK',
            'Sudan' => 'SD',
            'Suriname' => 'SR',
            'Svalbard and Jan Mayen' => 'SJ',
            'Sweden' => 'SE',
            'Switzerland' => 'CH',
            'Syria' => 'SY',
            'Taiwan' => 'TW',
            'Tajikistan' => 'TJ',
            'Tanzania' => 'TZ',
            'Thailand' => 'TH',
            'Timor-Leste' => 'TL',
            'Togo' => 'TG',
            'Tokelau' => 'TK',
            'Tonga' => 'TO',
            'Trinidad and Tobago' => 'TT',
            'Tunisia' => 'TN',
            'Turkey' => 'TR',
            'Turkmenistan' => 'TM',
            'Turks and Caicos Islands' => 'TC',
            'Tuvalu' => 'TV',
            'Uganda' => 'UG',
            'Ukraine' => 'UA',
            'United Arab Emirates' => 'AE',
            'United Kingdom' => 'GB',
            'United States' => 'US',
            'Uruguay' => 'UY',
            'Uzbekistan' => 'UZ',
            'Vanuatu' => 'VU',
            'Vatican City' => 'VA',
            'Venezuela' => 'VE',
            'Vietnam' => 'VN',
            'Wallis and Futuna' => 'WF',
            'Western Sahara' => 'EH',
            'Yemen' => 'YE',
            'Zambia' => 'ZM',
            'Zimbabwe' => 'ZW'
        ];        
        
        return $country_mapping[$english_name] ?? 'unknown';
    }

    private function get_flag_svg($code) {
        static $svg_cache = [];
        
        $code = strtolower(trim($code));
        
        if (isset($svg_cache[$code])) {
            return $svg_cache[$code];
        }
        
        $flag_path = plugin_dir_path(dirname(__FILE__)) . 'assets/icons/flags/' . $code . '.svg';
        $globe_path = plugin_dir_path(dirname(__FILE__)) . 'assets/icons/flags/globe.svg';
        
        $svg_path = file_exists($flag_path) ? $flag_path : $globe_path;
        
        $svg = file_get_contents($svg_path);
        
        // Basis-Reinigung und Klassen-Hinzufügung
        $svg = preg_replace('/<svg(.*?)>/', '<svg$1 class="at-flag-icon">', $svg);
        $svg = preg_replace('/(width|height)="[^"]*"/', '', $svg);
        
        // Entferne Whitespace und Zeilenumbrüche für JSON
        $svg = preg_replace('/\s+/', ' ', $svg);
        $svg = trim($svg);
        
        $svg_cache[$code] = $svg;
        
        return $svg;
    }

    private function get_browser_svg($browser) {
        static $svg_cache = [];
        
        // Normalize browser name
        $browser = strtolower(trim($browser));
        
        // Common browser name mappings
        $browser_mappings = [
            'microsoft edge' => 'edge',
            'edge' => 'edge',
            'chrome' => 'chrome',
            'firefox' => 'firefox',
            'safari' => 'safari',
            'opera' => 'opera',
            'samsung internet' => 'samsung',
            'ie' => 'ie',
            'internet explorer' => 'ie'
        ];
    
        $browser = $browser_mappings[$browser] ?? 'browser-default';
        
        // Check cache first
        if (isset($svg_cache[$browser])) {
            return $svg_cache[$browser];
        }
        
        // Pfade zu den SVGs
        $browser_path = plugin_dir_path(dirname(__FILE__)) . 'assets/icons/browsers/' . $browser . '.svg';
        $default_path = plugin_dir_path(dirname(__FILE__)) . 'assets/icons/browsers/browser-default.svg';
        
        // Versuche Browser-Icon zu laden, ansonsten nutze Default
        $svg_path = file_exists($browser_path) ? $browser_path : $default_path;
        
        // SVG laden und bereinigen
        $svg = file_get_contents($svg_path);
        
        // Klasse hinzufügen und width/height entfernen
        $svg = preg_replace('/<svg(.*?)>/', '<svg$1 class="at-browser-icon">', $svg);
        $svg = preg_replace('/(width|height)="[^"]*"/', '', $svg);
        
        // Whitespace bereinigen für JSON
        $svg = preg_replace('/\s+/', ' ', $svg);
        $svg = trim($svg);
        
        // Cache das Ergebnis
        $svg_cache[$browser] = $svg;
        
        return $svg;
    }

    private function get_date_range($range) {
        // WordPress Zeitzone holen
        $wp_timezone = wp_timezone();
        
        // Aktuelle Zeit in WP Zeitzone
        $now = new DateTime('now', $wp_timezone);
        $today = $now->format('Y-m-d');
        
        // Gestern in WP Zeitzone
        $yesterday = (new DateTime('now', $wp_timezone))->modify('-1 day')->format('Y-m-d');
        
        switch ($range) {
            case 'today':
                return ['start' => $today, 'end' => $today];
                
            case 'yesterday':
                return ['start' => $yesterday, 'end' => $yesterday];
                
            case 'last7days':
                $start = (new DateTime('now', $wp_timezone))
                    ->modify('-6 days')
                    ->modify('-1 day')  // Gestern als Endpunkt
                    ->format('Y-m-d');
                return [
                    'start' => $start,
                    'end' => $yesterday
                ];
                
            case 'last30days':
                $start = (new DateTime('now', $wp_timezone))
                    ->modify('-29 days')
                    ->modify('-1 day')  // Gestern als Endpunkt
                    ->format('Y-m-d');
                return [
                    'start' => $start,
                    'end' => $yesterday
                ];
                
            case 'thisMonth':
                $start = (new DateTime('first day of this month', $wp_timezone))->format('Y-m-d');
                return [
                    'start' => $start,
                    'end' => $today
                ];
                
            case 'lastMonth':
                $firstDayLastMonth = (new DateTime('first day of last month', $wp_timezone))->format('Y-m-d');
                $lastDayLastMonth = (new DateTime('last day of last month', $wp_timezone))->format('Y-m-d');
                return [
                    'start' => $firstDayLastMonth,
                    'end' => $lastDayLastMonth
                ];
                
            default:
                // Fallback auf last30days
                $start = (new DateTime('now', $wp_timezone))
                    ->modify('-29 days')
                    ->modify('-1 day')  // Gestern als Endpunkt
                    ->format('Y-m-d');
                return [
                    'start' => $start,
                    'end' => $yesterday
                ];
        }
    }

    public function format_duration($seconds) {
        if (is_string($seconds) && strpos($seconds, ':') !== false) {
            list($hours, $minutes) = explode(':', $seconds);

            $total_seconds = ($hours * 3600) + ($minutes * 60);
            $minutes = floor($total_seconds / 60);
            $remaining_seconds = floor($total_seconds % 60);
            
            return $minutes . 'm ' . $remaining_seconds . 's';
        }
        
        $minutes = floor($seconds / 60);
        $remaining_seconds = floor($seconds % 60);
        
        return $minutes . 'm ' . $remaining_seconds . 's';
    }

    private function get_access_token() {
        $cache_key = 'ga_access_token_' . $this->property_id;
        $cached_token = get_transient($cache_key);
        
        if ($cached_token !== false) {
            return $cached_token;
        }

        $token_url = 'https://oauth2.googleapis.com/token';
        
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT'
        ];
        
        $time = time();
        $claim_set = [
            'iss' => $this->credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
            'aud' => $this->credentials['token_uri'],
            'exp' => $time + 3600,
            'iat' => $time
        ];
        
        $jwt = $this->create_jwt($header, $claim_set, $this->credentials['private_key']);
        
        $response = wp_remote_post($token_url, [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ]
        ]);
        
        if (is_wp_error($response)) {
            throw new Exception('Token request failed: ' . $response->get_error_message());
        }
        
        $auth_data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($auth_data['access_token'])) {
            throw new Exception('No access token in response: ' . print_r($auth_data, true));
        }
        
        set_transient($cache_key, $auth_data['access_token'], 3000);
        
        return $auth_data['access_token'];
    }

    private function maybe_preload_ranges() {
        if (wp_doing_ajax()) return;
        
        $common_ranges = ['today', 'last7days', 'last30days'];
        foreach ($common_ranges as $range) {
            $cache_key = 'ga_data_' . $this->property_id . '_' . $range;
            if (false === get_transient($cache_key)) {
                wp_schedule_single_event(time(), 'load_analytics_range', [$range]);
            }
        }
    }

    public function preload_range($range) {
        $cache_key = 'ga_data_' . $this->property_id . '_' . $range;
        if (false === get_transient($cache_key)) {
            try {
                $data = $this->fetch_from_api($range);
                $formatted_data = $this->format_analytics_data($data);
                $duration = $this->cache_duration[$range] ?? 7200;
                set_transient($cache_key, $formatted_data, $duration);
            } catch (Exception $e) {
                error_log('Failed to preload analytics range ' . $range . ': ' . $e->getMessage());
            }
        }
    }

    private function create_jwt($header, $claim_set, $private_key) {
        $encoded_header = $this->base64url_encode(json_encode($header));
        $encoded_claim_set = $this->base64url_encode(json_encode($claim_set));
        
        $signature_input = $encoded_header . '.' . $encoded_claim_set;
        
        $key_id = openssl_pkey_get_private($private_key);
        openssl_sign($signature_input, $signature, $key_id, 'SHA256');
        openssl_free_key($key_id);
        
        $encoded_signature = $this->base64url_encode($signature);
        
        return $signature_input . '.' . $encoded_signature;
    }
    
    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}