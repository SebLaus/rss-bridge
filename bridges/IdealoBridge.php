<?php

class IdealoBridge extends BridgeAbstract
{
    const NAME = 'idealo.de / idealo.fr / idealo.es Bridge';
    const URI = 'https://www.idealo.de';
    const DESCRIPTION = 'Tracks the price for a product on idealo.de / idealo.fr / idealo.es. Pricealarm if specific price is set';
    const MAINTAINER = 'SebLaus';
    const CACHE_TIMEOUT = 60 * 30; // 30 min
    const PARAMETERS = [
        [
            'link' => [
                'name'          => 'idealo.de / idealo.fr / idealo.es Link to productpage',
                'required'      => true,
                'exampleValue'  => 'https://www.idealo.de/preisvergleich/OffersOfProduct/202007367_-s7-pro-ultra-roborock.html'
            ],
            'excludenew' => [
                'name' => 'Priceupdate: Do not track new items',
                'type' => 'checkbox',
                'value' => 'c'
            ],
            'excludeused' => [
                'name' => 'Priceupdate: Do not track used items',
                'type' => 'checkbox',
                'value' => 'uc'
            ],
            'maxpricenew' => [
                'name'          => 'Pricealarm: Maximum price for new Product',
                'type'          => 'number'
            ],
            'maxpriceused' => [
                'name'          => 'Pricealarm: Maximum price for used Product',
                'type'          => 'number'
            ],
        ]
    ];

    public function getIcon()
    {
        return 'https://cdn.idealo.com/storage/ids-assets/ico/favicon.ico';
    }

    /**
     * Returns the RSS Feed title when a RSS feed is rendered
     * @return string the RSS feed Title
     */
    private function getFeedTitle()
    {
        $cacheduration = 604800;
        $link = $this->getInput('Link');
        $keytitle = $link . 'TITLE';
        $product = $this->loadCacheValue($keytitle, $cacheduration);

        // The cache does not contain the title of the bridge, we must get it and save it in the cache
        if ($product === null) {
            $header = [
                'user-agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2.1 Safari/605.1.15'
            ];
            $html = getSimpleHTMLDOM($link, $header);
            $product = $html->find('.oopStage-title', 0)->find('span', 0)->plaintext;
            $this->saveCacheValue($keytitle, $product);
        }

        $maxpriceused = $this->getInput('maxpriceused');
        $maxpricenew = $this->getInput('maxpricenew');
        $titleparts = [];

        $titleparts[] = $product;

        // Add Max Prices to the title
        if ($maxpriceused !== null) {
            $titleparts[] = 'Max Price Used : ' . $maxpriceused . '€';
        }
        if ($maxpricenew !== null) {
            $titleparts[] = 'Max Price New : ' . $maxpricenew . '€';
        }

        $title = implode(' ', $titleparts);


        return $title . ' - ' . $this::NAME;
    }


    /**
     * Returns the Price Trend emoji
     * @return string the Price Trend Emoji
     */
    private function getPriceTrend($newprice, $oldprice)
    {
        // In case there is no old Price, then show no trend
        if ($oldprice === null) {
            $trend = '';
        } else if ($newprice > $oldprice) {
            $trend = '&#x2197;';
        } else if ($newprice == $oldprice) {
            $trend = '&#x27A1;';
        } else if ($newprice < $oldprice) {
            $trend = '&#x2198;';
        }
        return $trend;
    }
    public function collectData()
    {
        // Needs header with user-agent to function properly.
        $header = [
            'user-agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2.1 Safari/605.1.15'
        ];

        $link = $this->getInput('link');
        $html = getSimpleHTMLDOM($link, $header);

        // Get Productname
        $titleobj = $html->find('.oopStage-title', 0);
        $productname = $titleobj->find('span', 0)->plaintext;

        // Create product specific Cache Keys with the link
        $keynew = $link;
        $keynew .= 'NEW';

        $keyused = $link;
        $keyused .= 'USED';

        // Load previous Price
        $oldpricenew = $this->loadCacheValue($keynew);
        $oldpriceused = $this->loadCacheValue($keyused);

        // First button is new. Found at oopStage-conditionButton-wrapper-text class (.)
        $firstbutton = $html->find('.oopStage-conditionButton-wrapper-text', 0);
        if ($firstbutton) {
            $pricenew = $firstbutton->find('strong', 0)->plaintext;
            // Save current price
            $this->saveCacheValue($keynew, $pricenew);
        }

        // Second Button is used
        $secondbutton = $html->find('.oopStage-conditionButton-wrapper-text', 1);
        if ($secondbutton) {
            $priceused = $secondbutton->find('strong', 0)->plaintext;
            // Save current price
            $this->saveCacheValue($keyused, $priceused);
        }

        // Only continue if a price has changed
        if ($pricenew != $oldpricenew || $priceused != $oldpriceused) {
            // Get Product Image
            $image = $html->find('.datasheet-cover-image', 0)->src;

            $content = '';

            // Generate Content
            if (isset($pricenew) && $pricenew > 1) {
                $content .= sprintf('<p><b>Price New:</b><br>%s %s</p>', $pricenew, $this->getPriceTrend($pricenew, $oldpricenew));
                $content .= "<p><b>Price New before:</b><br>$oldpricenew</p>";
            }

            if ($this->getInput('maxpricenew') != '') {
                $content .= sprintf('<p><b>Max Price New:</b><br>%s,00 €</p>', $this->getInput('maxpricenew'));
            }

            if (isset($PriceUsed) && $PriceUsed > 1) {
                $content .= sprintf('<p><b>Price Used:</b><br>%s %s</p>', $priceused, $this->getPriceTrend($priceused, $oldpriceused));
                $content .= "<p><b>Price Used before:</b><br>$oldpriceused</p>";
            }

            if ($this->getInput('maxpriceused') != '') {
                $content .= sprintf('<p><b>Max Price Used:</b><br>%s,00 €</p>', $this->getInput('maxpriceused'));
            }

            $content .= "<img src=$image>";


            $now = date('d.m.j H:m');

            $pricealarm = 'Pricealarm %s: %s %s %s';

            // Currently under Max new price
            if ($this->getInput('maxpricenew') != '') {
                if (isset($pricenew) && $pricenew < $this->getInput('maxpricenew')) {
                    $title = sprintf($pricealarm, 'New', $pricenew, $productname, $now);
                    $item = [
                        'title'     => $title,
                        'uri'       => $link,
                        'content'   => $content,
                        'uid'       => md5($title)
                    ];
                    $this->items[] = $item;
                }
            }

            // Currently under Max used price
            if ($this->getInput('MaxPriceUsed') != '') {
                if (isset($priceused) && $priceused < $this->getInput('maxpriceused')) {
                    $title = sprintf($pricealarm, 'Used', $priceused, $productname, $now);
                    $item = [
                        'title'     => $title,
                        'uri'       => $link,
                        'content'   => $content,
                        'uid'       => md5($title)
                    ];
                    $this->items[] = $item;
                }
            }

            // General Priceupdate
            if ($this->getInput('maxpriceused') == '' && $this->getInput('maxpricenew') == '') {
                // check if a relevant pricechange happened
                if (
                    (!$this->getInput('excludenew') && $pricenew != $oldpricenew ) ||
                    (!$this->getInput('excludeused') && $priceused != $oldpriceused )
                ) {
                    $title = 'Priceupdate! ';

                    if (!$this->getInput('excludenew')) {
                        $title .= 'NEW' . $this->getPriceTrend($pricenew, $oldpricenew) . ' ';
                    }

                    if (!$this->getInput('excludeused')) {
                        $title .= 'USED' . $this->getPriceTrend($priceused, $oldpriceused) . ' ';
                    }
                    $title .= $productname;
                    $title .= ' ';
                    $title .= $now;

                    $item = [
                        'title'     => $title,
                        'uri'       => $link,
                        'content'   => $content,
                        'uid'       => md5($title)
                    ];
                    $this->items[] = $item;
                }
            }
        }
    }

    /**
     * Returns the RSS Feed title according to the parameters
     * @return string the RSS feed Tile
     */
    public function getName()
    {
        switch ($this->queriedContext) {
            case '0':
                return $this->getFeedTitle();
            default:
                return parent::getName();
        }
    }
}
