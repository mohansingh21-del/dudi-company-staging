<p>Hello,</p>
@if (!empty($outOfStock))
    <p>The product <strong>{{ $productName }}</strong> is out of stock@if (!empty($storeName)) at <strong>{{ $storeName }}</strong>@endif.</p>
    <p>It cannot be issued to employees or service records until it is restocked.</p>
@else
    <p>The product <strong>{{ $productName }}</strong> has reached its minimum stock level@if (!empty($storeName)) at <strong>{{ $storeName }}</strong>@endif.</p>
    <p>Currently left quantity: <strong>{{ $leftQuantity }}</strong></p>
    <p>It can still be issued, but please restock it soon.</p>
@endif
<p>Thank you.</p>
