<p>Hello,</p>
@if (!empty($storeName))
    <p>The product <strong>{{ $productName }}</strong> has reached its minimum stock level at <strong>{{ $storeName }}</strong>.</p>
    <p>Currently left quantity: <strong>{{ $leftQuantity }}</strong></p>
    <p>Please restock it at that store so that it can continue to be issued to service records.</p>
@else
    <p>The product <strong>{{ $productName }}</strong> has reached its minimum stock level in inventory.</p>
    <p>Currently left quantity: <strong>{{ $leftQuantity }}</strong></p>
    <p>Please update the quantity of the product so that assigning the product to employees can continue.</p>
@endif
<p>Thank you.</p>
