<?php

declare(strict_types=1);

/**
 * The shop page: one product, one button. Posting it creates an order and a payment intent.
 */

require __DIR__ . '/bootstrap.php';

?><!doctype html>
<meta charset="utf-8">
<title>P2Flux demo shop</title>
<h1>P2Flux demo shop</h1>
<p>One imaginary product, 12.50 USDC.</p>
<form method="post" action="create.php">
    <button type="submit">Buy</button>
</form>
