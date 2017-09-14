<?php

date_default_timezone_set ('UTC');

include 'build/ccxt.php';

date_default_timezone_set ('UTC');

function style ($s, $style) { return $style . $s . "\033[0m"; }
function green     ($s) { return style ($s, "\033[92m"); }
function blue      ($s) { return style ($s, "\033[94m"); }
function yellow    ($s) { return style ($s, "\033[93m"); }
function red       ($s) { return style ($s, "\033[91m"); }
function pink      ($s) { return style ($s, "\033[95m"); }
function bold      ($s) { return style ($s, "\033[1m"); }
function underline ($s) { return style ($s, "\033[4m"); }
function dump ($s) { echo implode (' ', func_get_args ()) . "\n"; }

$exchanges = null;

// $shortopts = '';
// $longopts = array (
//     "nonce::", // '::' means optional, ':' means required
// );

// $options = getopt ($shortopts, $longopts);
// var_dump ($options);
// exit ();

//-----------------------------------------------------------------------------

foreach (\ccxt\Exchange::$exchanges as $id) {
    $exchange = '\\ccxt\\' . $id;
    $exchanges[$id] = new $exchange (array ('verbose' => true));
}

$config = json_decode (file_get_contents ('./keys.json'), true);

foreach ($config as $id => $params)
    foreach ($params as $key => $value)
        if (array_key_exists ($id, $exchanges))
            $exchanges[$id]->$key = $value;

$exchanges['gdax']->urls['api'] = 'https://api-public.sandbox.gdax.com';
$exchanges['anxpro']->proxy = 'https://cors-anywhere.herokuapp.com/';

function test_exchange_symbol_ticker ($exchange, $symbol) {
    dump (green ($exchange->id), green ($symbol), 'fetching ticker...');
    $ticker = $exchange->fetch_ticker ($symbol);
    dump (green ($exchange->id), green ($symbol), 'ticker:', implode (' ', array (
        $ticker['datetime'],
        'high: '    . $ticker['high'],
        'low: '     . $ticker['low'],
        'bid: '     . $ticker['bid'],
        'ask: '     . $ticker['ask'],
        'volume: '  . $ticker['quoteVolume'])));
}

function test_exchange_symbol_orderbook ($exchange, $symbol) {
    dump (green ($exchange->id), green ($symbol), 'fetching order book...');
    $orderbook = $exchange->fetch_order_book ($symbol);
    dump (green ($exchange->id), green ($symbol), 'order book:', implode (' ', array (
        $orderbook['datetime'],
        'bid: '       . @$orderbook['bids'][0][0],
        'bidVolume: ' . @$orderbook['bids'][0][1],
        'ask: '       . @$orderbook['asks'][0][0],
        'askVolume: ' . @$orderbook['asks'][0][1])));
}

function test_exchange_symbol ($exchange, $symbol) {
    $delay = $exchange->rateLimit * 1000;
    usleep ($delay);
    test_exchange_symbol_ticker ($exchange, $symbol);
    usleep ($delay);
    if ($exchange->id == 'coinmarketcap')
        dump (var_export ($exchange->fetchGlobal ()));
    else
        test_exchange_symbol_orderbook ($exchange, $symbol);
}

function load_exchange ($exchange) {
    $markets = $exchange->load_markets ();
    $symbols = array_keys ($markets);
    dump (green ($exchange->id), green (count ($symbols)), 'symbols:', implode (', ', $symbols));
}

function try_all_proxies ($exchange, $proxies) {

    $current_proxy = 0;
    $max_retries = count ($proxies);

    // a special case for ccex
    if ($exchange->id == 'ccex')
        $currentProxy = 1;

    for ($i = 0; $i < $max_retries; $i++) {

        try {

            $exchange->proxy = $proxies[$current_proxy];
            $current_proxy = (++$current_proxy) % count ($proxies);

            load_exchange ($exchange);
            test_exchange ($exchange);
            break;

        } catch (\ccxt\RequestTimeout $e) {
            dump (yellow ('[Timeout Error] ' . $e->getMessage () . ' (ignoring)'));
        } catch (\ccxt\DDoSProtection $e) {
            dump (yellow ('[DDoS Protection Error] ' . $e->getMessage () . ' (ignoring)'));
        } catch (\ccxt\AuthenticationError $e) {
            dump (yellow ('[Authentication Error] ' . $e->getMessage () . ' (ignoring)'));
        } catch (\ccxt\ExchangeNotAvailable $e) {
            dump (yellow ('[Exchange Not Available] ' . $e->getMessage () . ' (ignoring)'));
        } catch (\ccxt\NotSupported $e) {
            dump (yellow ('[Not Supported] ' . $e->getMessage () . ' (ignoring)'));
        } catch (\ccxt\NetworkError $e) {
            dump (yellow ('[Network Error] ' . $e->getMessage () . ' (ignoring)'));
        } catch (\ccxt\ExchangeError $e) {
            dump (yellow ('[Exchange Error] ' . $e->getMessage () . ' (ignoring)'));
        } catch (Exception $e) {
            dump (red ('[Error] ' . $e->getMessage ()));
        }
    }
}

function test_exchange ($exchange) {

    $delay = $exchange->rateLimit * 1000;

    $symbol = $exchange->symbols[0];
    $symbols = array (
        'BTC/USD',
        'BTC/CNY',
        'BTC/EUR',
        'BTC/ETH',
        'ETH/BTC',
        'BTC/JPY',
        'LTC/BTC',
    );

    foreach ($symbols as $s) {
        if (in_array ($s, $exchange->symbols)) {
            $symbol = $s;
            break;
        }
    }

    if (strpos ($symbol, '.d') === false) {

        dump (green ('SYMBOL:'), green ($symbol));

        test_exchange_symbol ($exchange, $symbol);
    }

    // usleep ($delay);
    // $trades = $exchange->fetch_trades (array_keys ($markets)[0]);
    // var_dump ($trades);

    if ((!$exchange->apiKey) or (strlen ($exchange->apiKey) < 1))
        return;

    usleep ($delay);

    $balance = $exchange->fetch_balance ();
    print_r ($balance);
}

$proxies = array (
    '',
    'https://cors-anywhere.herokuapp.com/',
    'https://crossorigin.me/',
    // 'http://cors-proxy.htmldriven.com/?url=', // we don't want this for now
);

if (count ($argv) > 1) {

    if ($exchanges[$argv[1]]) {

        $id = $argv[1];
        $exchange = $exchanges[$id];

        dump (green ('EXCHANGE:'), green ($exchange->id));

        if (count ($argv) > 2) {

            load_exchange ($exchange);
            test_exchange_symbol ($exchange, $argv[2]);

        } else {

            try_all_proxies ($exchange, $proxies);
        }

    } else {

        echo $argv[1] + " not found.\n";
    }

} else {

    foreach ($exchanges as $id => $exchange) {

        try_all_proxies ($exchange, $proxies);
    }

}

?>