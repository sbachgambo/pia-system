<?php
/**
 * Monthly PIA → CBN service-fee invoice, laid out after the client's sample
 * ("PIA Invoice June 2023"). mPDF-targeted: tables only, no flexbox/grid.
 * Unlike the CCI this one carries the agency's own letterhead — it is the
 * agency's bill.
 *
 * @var \App\Http\View\Renderer $this
 * @var array{number:string,issued_at:string,label:string} $invoice
 * @var array{lines:list<array<string,mixed>>,cci_count:int,fob_ngn:float,fee_ngn:float,fee_rate:float} $figures
 * @var string $words
 * @var array<string,mixed> $settings   invoice_settings
 * @var array{name:string,address:string} $company
 * @var string $feeLabel e.g. "0.35%"
 */
use App\Documents\Fmt;

$e = fn (mixed $v) => $this->e($v);
$logoPath = __DIR__ . '/../../public/assets/brand/mark-96.png';
$logo = is_file($logoPath) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPath)) : null;
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: Arial, sans-serif; font-size: 10pt; color: #111; }
  .head { width: 100%; border-bottom: 3px solid #ec3237; margin-bottom: 14px; }
  .head td { vertical-align: middle; }
  .logo img { width: 48px; height: 48px; }
  .company { font-size: 17pt; font-weight: bold; color: #363436; }
  .small { font-size: 8pt; color: #555; }
  .meta { width: 100%; margin-bottom: 12px; }
  .meta td { vertical-align: top; }
  .inv-no { font-size: 11pt; font-weight: bold; color: #ec3237; text-align: right; }
  .inv-date { text-align: right; }
  .title { text-align: center; font-size: 12pt; font-weight: bold; text-decoration: underline; margin: 10px 0 6px; }
  .desc { margin-bottom: 12px; }
  table.lines { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
  table.lines th, table.lines td { border: 1px solid #555; padding: 5px 6px; font-size: 9pt; }
  table.lines th { background: #363436; color: #fff; text-align: center; }
  .num { text-align: right; }
  .center { text-align: center; }
  .total td { font-weight: bold; background: #f2f2f2; }
  .words { margin: 8px 0 16px; }
  .pay td { padding: 2px 8px 2px 0; }
  .sig { margin-top: 44px; width: 260px; border-top: 1px solid #333; padding-top: 4px; font-size: 9pt; }
</style>
</head>
<body>

<table class="head">
  <tr>
    <?php if ($logo): ?><td class="logo" style="width:56px;"><img src="<?= $e($logo) ?>" alt=""></td><?php endif; ?>
    <td><span class="company"><?= $e($company['name']) ?></span><br><span class="small"><?= nl2br($e($company['address'])) ?></span></td>
  </tr>
</table>

<table class="meta">
  <tr>
    <td style="width:60%;"><?= nl2br($e($settings['addressee'])) ?></td>
    <td style="width:40%;">
      <div class="inv-no">INVOICE NO: <?= $e($invoice['number']) ?></div>
      <div class="inv-date"><b>DATE:</b> <?= $e(Fmt::date($invoice['issued_at'])) ?></div>
    </td>
  </tr>
</table>

<div class="title">SERVICE FEE (<?= $e($invoice['label']) ?>)</div>
<div class="desc"><b>TO:</b> <?= $e($settings['service_description']) ?></div>

<table class="lines">
  <thead>
    <tr>
      <th>REGION</th>
      <th>NO. OF CCIs ISSUED</th>
      <th>CURRENCY</th>
      <th>F.O.B VALUE</th>
      <th>EXCHANGE RATE</th>
      <th>F.O.B VALUE (&#8358;)</th>
      <th>FEES PAYABLE (<?= $e($feeLabel) ?> OF F.O.B) (&#8358;)</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($figures['lines'] as $l): ?>
    <tr>
      <td><?= $e($l['region']) ?></td>
      <td class="center"><?= $e($l['cci_count']) ?></td>
      <td class="center"><?= $e($l['currency']) ?></td>
      <td class="num"><?= $e(Fmt::money($l['fob'])) ?></td>
      <td class="num"><?= $e(Fmt::money($l['exchange_rate'], 4)) ?></td>
      <td class="num"><?= $e(Fmt::money($l['fob_ngn'])) ?></td>
      <td class="num"><?= $e(Fmt::money($l['fee_ngn'])) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total">
      <td>TOTAL</td>
      <td class="center"><?= $e($figures['cci_count']) ?></td>
      <td></td>
      <td></td>
      <td></td>
      <td class="num"><?= $e(Fmt::money($figures['fob_ngn'])) ?></td>
      <td class="num"><?= $e(Fmt::money($figures['fee_ngn'])) ?></td>
    </tr>
  </tbody>
</table>

<div class="words"><b>Amount in Words:</b><br><?= $e($words) ?></div>

<div><b>PLS PAY:</b></div>
<table class="pay">
  <tr><td>ACCOUNT NAME:</td><td><b><?= $e($settings['bank_account_name']) ?></b></td></tr>
  <tr><td>ACCOUNT NUMBER:</td><td><b><?= $e($settings['bank_account_number']) ?></b></td></tr>
  <tr><td>BANK:</td><td><b><?= $e($settings['bank_name']) ?></b></td></tr>
</table>

<div class="sig">AUTHORISED SIGNATORY<br>FOR: <?= $e(strtoupper($company['name'])) ?></div>

</body>
</html>
