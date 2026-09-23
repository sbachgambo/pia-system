<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var array $document     {type, document_number, issued_at, copy}
 * @var array $company      {name, address, representative_title} — the issuing agency (signature block only)
 * @var array $inspection   {uuid, scheduled_at, location_type, location_detail}
 * @var array $client
 * @var array $consignment
 * @var array $trade
 * @var array $shipment
 *
 * mPDF-targeted HTML — this is a standalone document, not wrapped in the
 * console layout. Keep the CSS mPDF-safe (no flexbox/grid; tables only).
 */
use App\Documents\Fmt;

$title = $document['type'] === 'NNCI'
    ? 'NON-NEGOTIABLE CERTIFICATE OF INSPECTION'
    : 'CLEAN CERTIFICATE OF INSPECTION';
$numberLabel = $document['type'] === 'NNCI' ? 'NNCI No.' : 'CCI No.';
$e = fn (mixed $v) => $this->e($v);

// The header carries the CLIENT's name (client decision, Sep 2026) — not the
// agency's logo or name. The agency still signs at the foot of the certificate.
$ness = $shipment['ness_computed'] ?? null;
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: Arial, sans-serif; font-size: 9.5pt; color: #111; }
  .head-table { width: 100%; border-bottom: 3px solid #ec3237; margin-bottom: 6px; }
  .head-table td { vertical-align: middle; }
  .client-name { font-size: 16pt; font-weight: bold; color: #363436; }
  .cci-number { font-size: 13pt; font-weight: bold; color: #ec3237; }
  .doc-title { font-size: 11pt; font-weight: bold; text-align: right; color: #363436; }
  .doc-meta { text-align: right; font-size: 9.5pt; }
  .doc-meta b { color: #ec3237; }
  table.form { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
  table.form td, table.form th {
    border: 1px solid #999; padding: 3px 5px; vertical-align: top; font-size: 9pt;
  }
  .section-bar {
    background: #363436; color: #fff; font-weight: bold; padding: 3px 6px;
    font-size: 9.5pt; margin-top: 8px; border-left: 4px solid #ec3237;
  }
  .fnum { font-weight: bold; color: #555; }
  .val { font-weight: bold; }
  .w-25 { width: 25%; } .w-50 { width: 50%; } .w-33 { width: 33.33%; }
  .right { text-align: right; }
  .center { text-align: center; }
  .small { font-size: 7.5pt; color: #666; }
  .sig-block { margin-top: 26px; }
  .sig-line { border-top: 1px solid #333; width: 220px; margin-top: 26px; padding-top: 3px; font-size: 8.5pt; }
  .footer-note { margin-top: 10px; font-size: 7.5pt; color: #777; }
</style>
</head>
<body>

<table class="head-table">
  <tr>
    <td class="w-50"><span class="client-name"><?= $e($client['name']) ?></span><br>
      <span class="small"><?= nl2br($e($client['address'])) ?></span></td>
    <td class="w-50">
      <div class="doc-title"><?= $e($title) ?></div>
      <div class="doc-meta">
        <span class="cci-number"><?= $e($numberLabel) ?> <?= $e($document['document_number']) ?></span><br>
        <b>NXP No.:</b> <?= $e(Fmt::orDash($consignment['form_nxp_number'])) ?> &nbsp;
        <b>Copy:</b> <?= $e($document['copy'] ?? 'Original') ?><br>
        <b>Date:</b> <?= $e(Fmt::date($document['issued_at'])) ?>
      </div>
    </td>
  </tr>
</table>

<div class="section-bar">DECLARATION</div>
<table class="form">
  <tr>
    <td class="w-33"><span class="fnum">1. N.X.P. Form No.:</span><br><span class="val"><?= $e(Fmt::orDash($consignment['form_nxp_number'])) ?></span></td>
    <td class="w-33"><span class="fnum">2. N.E.P.C. No.:</span><br><span class="val"><?= $e(Fmt::orDash($trade['nepc_number'])) ?></span></td>
    <td class="w-33"><span class="fnum">3. YEAR:</span><br><span class="val"><?= $e(Fmt::date($document['issued_at'], 'Y')) ?></span></td>
  </tr>
  <tr>
    <td><span class="fnum">4. H.S. Code:</span><br><span class="val"><?= $e(Fmt::orDash($consignment['hs_code'])) ?></span></td>
    <td><span class="fnum">5. ORIGIN:</span><br><span class="val"><?= $e($consignment['origin_country']) ?></span></td>
    <td><span class="fnum">6. DATE:</span><br><span class="val"><?= $e(Fmt::date($document['issued_at'])) ?></span></td>
  </tr>
  <tr>
    <td colspan="2">
      <span class="fnum">7. EXPORTER'S NAME AND ADDRESS:</span><br>
      <span class="val"><?= $e($client['name']) ?></span><br><?= nl2br($e($client['address'])) ?>
    </td>
    <td>
      <span class="fnum">9. RC No.:</span><br><span class="val"><?= $e(Fmt::orDash($client['rc_number'])) ?></span>
    </td>
  </tr>
  <tr>
    <td colspan="3">
      <span class="fnum">8. IMPORTER'S NAME AND ADDRESS:</span><br>
      <span class="val"><?= $e(Fmt::orDash($trade['importer_name'])) ?></span><br><?= nl2br($e($trade['importer_address'] ?? '')) ?>
    </td>
  </tr>
  <tr>
    <td><span class="fnum">10. EXPORTER'S BANK:</span><br><span class="val"><?= $e(Fmt::orDash($trade['exporter_bank_name'])) ?></span></td>
    <td><span class="fnum">11. IMPORTER'S BANK:</span><br><span class="val"><?= $e(Fmt::orDash($trade['importer_bank_name'])) ?></span></td>
    <td><span class="fnum">12/13. BANK REFERENCE:</span><br><span class="val"><?= $e(Fmt::orDash($trade['bank_reference'])) ?></span></td>
  </tr>
  <tr>
    <td><span class="fnum">14. GOODS TO BE EXPORTED:</span><br><span class="val"><?= $e($consignment['product_category']) ?></span></td>
    <td><span class="fnum">15. UNITS:</span><br><span class="val"><?= $e($consignment['unit_of_measure']) ?></span></td>
    <td><span class="fnum">16. QUANTITY:</span><br><span class="val"><?= $e(Fmt::money($consignment['quantity'])) ?></span></td>
  </tr>
  <tr>
    <td><span class="fnum">17. UNIT PRICE:</span><br><span class="val"><?= $e($consignment['currency']) ?> <?= $e(Fmt::money($consignment['unit_price'])) ?></span></td>
    <td><span class="fnum">18. EXPORTER'S INVOICE NUMBER:</span><br><span class="val"><?= $e(Fmt::orDash($trade['invoice_number'])) ?></span></td>
    <td><span class="fnum">19. INVOICE DATE:</span><br><span class="val"><?= $e(Fmt::date($trade['invoice_date'])) ?></span></td>
  </tr>
  <tr>
    <td><span class="fnum">20. BASIS OF SALE:</span><br><span class="val"><?= $e(Fmt::orDash($trade['basis_of_sale'])) ?></span></td>
    <td><span class="fnum">21. METHOD OF PAYMENT:</span><br><span class="val"><?= $e(Fmt::orDash($trade['method_of_payment'])) ?></span></td>
    <td><span class="fnum">22. CURRENCY:</span><br><span class="val"><?= $e($consignment['currency']) ?></span></td>
  </tr>
  <tr>
    <td><span class="fnum">23. FOB INVOICE VALUE:</span><br><span class="val"><?= $e(Fmt::money($consignment['declared_value'])) ?></span></td>
    <td><span class="fnum">24. FREIGHT CHARGES:</span><br><span class="val"><?= $e(Fmt::orDash(Fmt::money($trade['freight_charges']))) ?></span></td>
    <td><span class="fnum">25. INSURANCE CHARGES:</span><br><span class="val"><?= $e(Fmt::orDash(Fmt::money($trade['insurance_charges']))) ?></span></td>
  </tr>
</table>

<div class="section-bar">DECLARED SHIPPING DETAILS</div>
<table class="form">
  <tr>
    <td class="w-25"><span class="fnum">27. SHIPMENT DATE:</span><br><span class="val"><?= $e(Fmt::date($shipment['shipment_date'])) ?></span></td>
    <td class="w-25"><span class="fnum">27B. SHIPPING AGENT:</span><br><span class="val"><?= $e(Fmt::orDash($shipment['shipping_agent'])) ?></span></td>
    <td class="w-25"><span class="fnum">28. CARRIER / VESSEL:</span><br><span class="val"><?= $e(Fmt::orDash($shipment['carrier_vessel'])) ?></span></td>
    <td class="w-25"><span class="fnum">28B. LOADING REF No.:</span><br><span class="val"><?= $e(Fmt::orDash($shipment['loading_ref_no'])) ?></span></td>
  </tr>
  <tr>
    <td><span class="fnum">29. PORT / POINT OF EXPORT:</span><br><span class="val"><?= $e(Fmt::orDash($inspection['location_detail'])) ?></span></td>
    <td><span class="fnum">30. DESTINATION:</span><br><span class="val"><?= $e($consignment['destination_country']) ?></span></td>
    <td colspan="2"><span class="fnum">31. CONTAINER NUMBERS:</span><br><span class="val"><?= $e(Fmt::orDash($shipment['container_numbers'])) ?></span></td>
  </tr>
</table>

<div class="section-bar">PRESHIPMENT INSPECTION FINDINGS</div>
<table class="form">
  <tr>
    <td class="w-25"><span class="fnum">32. H.S. Code:</span><br><span class="val"><?= $e(Fmt::orDash($consignment['hs_code'])) ?></span></td>
    <td class="w-25"><span class="fnum">34. UNITS:</span><br><span class="val"><?= $e($consignment['unit_of_measure']) ?></span></td>
    <td class="w-25"><span class="fnum">34B. INSPECTION DATE:</span><br><span class="val"><?= $e(Fmt::date($inspection['scheduled_at'])) ?></span></td>
    <td class="w-25"><span class="fnum">35. QUANTITY:</span><br><span class="val"><?= $e(Fmt::money($consignment['quantity'])) ?></span></td>
  </tr>
  <tr>
    <td colspan="2"><span class="fnum">33. GOODS TO BE EXPORTED:</span><br><span class="val"><?= $e($consignment['product_category']) ?></span></td>
    <td><span class="fnum">37B. GROSS WEIGHT:</span><br><span class="val"><?= $e(Fmt::orDash(Fmt::money($shipment['gross_weight_kg']))) ?> <?= $shipment['gross_weight_kg'] !== null ? 'kg' : '' ?></span></td>
    <td><span class="fnum">38B. NET WEIGHT:</span><br><span class="val"><?= $e(Fmt::orDash(Fmt::money($shipment['net_weight_kg']))) ?> <?= $shipment['net_weight_kg'] !== null ? 'kg' : '' ?></span></td>
  </tr>
  <tr>
    <td colspan="2"><span class="fnum">37. PACKING DETAILS:</span><br><span class="val"><?= $e(Fmt::orDash($shipment['packing_details'])) ?></span></td>
    <td colspan="2"><span class="fnum">38. QUALITY:</span><br><span class="val"><?= $e(Fmt::orDash($shipment['quality_remark'])) ?></span></td>
  </tr>
  <tr>
    <td colspan="4">
      <span class="fnum">39. TOTAL FOB VALUE OF GOODS:</span>
      <span class="val"><?= $e($consignment['currency']) ?> <?= $e(Fmt::money($consignment['declared_value'])) ?></span><br>
      <span class="fnum">40. IN WORDS:</span> <span class="val"><?= $e($consignment['total_value_words']) ?></span>
    </td>
  </tr>
  <tr>
    <td><span class="fnum">41. FREIGHT CHARGES:</span><br><span class="val"><?= $e(Fmt::orDash(Fmt::money($trade['freight_charges']))) ?></span></td>
    <td><span class="fnum">42. INSURANCE CHARGES:</span><br><span class="val"><?= $e(Fmt::orDash(Fmt::money($trade['insurance_charges']))) ?></span></td>
    <td><span class="fnum">43. BASIS OF SALE:</span><br><span class="val"><?= $e(Fmt::orDash($trade['basis_of_sale'])) ?></span></td>
    <td><span class="fnum">44. FOREX PROCEEDS DUE:</span><br><span class="val"><?= $e(Fmt::money($consignment['declared_value'])) ?></span></td>
  </tr>
  <tr>
    <td><span class="fnum">45. EXCHANGE DATE:</span><br><span class="val"><?= $e(Fmt::orDash(Fmt::date($shipment['forex_exchange_date']))) ?></span></td>
    <td><span class="fnum">46. CURRENCY:</span><br><span class="val"><?= $e($consignment['currency']) ?></span></td>
    <td colspan="2"><span class="fnum">47. RATE OF EXCHANGE:</span><br><span class="val"><?= $e(Fmt::orDash(Fmt::money($shipment['exchange_rate'], 4))) ?></span></td>
  </tr>
  <tr>
    <td><span class="fnum">48. NESS CHARGES PAID ON NXP:</span><br><span class="val"><?= $e(Fmt::orDash(Fmt::money($shipment['ness_charges_paid']))) ?></span></td>
    <td><span class="fnum">49. RECEIPT No.:</span><br><span class="val"><?= $e(Fmt::orDash($shipment['ness_receipt_no'])) ?></span></td>
    <td><span class="fnum">50. ACTUAL NESS CHARGES PAYABLE:</span><br>
      <?php if ($shipment['ness_actual_payable'] !== null): ?>
        <span class="val"><?= $e(Fmt::money($shipment['ness_actual_payable'])) ?></span>
      <?php elseif ($ness !== null): ?>
        <span class="val"><?= $e($ness['currency']) ?> <?= $e(Fmt::money($ness['amount'])) ?></span><br>
        <span class="small">(<?= $e($ness['rate']) ?> of FOB)</span>
      <?php else: ?>
        <span class="val">—</span>
      <?php endif; ?>
    </td>
    <td>
      <span class="fnum">51/52. BALANCE PAID / RECEIPT No.:</span><br>
      <span class="val"><?= $e(Fmt::orDash(Fmt::money($shipment['ness_balance_paid']))) ?> <?= $e($shipment['ness_balance_receipt_no'] ? '(' . $shipment['ness_balance_receipt_no'] . ')' : '') ?></span>
    </td>
  </tr>
</table>

<table class="form" style="border:none;">
  <tr>
    <td style="border:none; width:50%;">
      <div class="sig-block">
        <div class="sig-line">Authorised Representative</div>
      </div>
    </td>
    <td style="border:none; width:50%;" class="right">
      <div class="sig-block">
        <div class="sig-line" style="margin-left:auto;">For: <?= $e($company['name']) ?></div>
      </div>
    </td>
  </tr>
</table>

<div class="footer-note">
  Document <?= $e($document['document_number']) ?> — system-generated and tamper-evident (SHA-256 content hash +
  HMAC signature held by the issuing office, checked against this document number on request).
</div>

</body>
</html>
