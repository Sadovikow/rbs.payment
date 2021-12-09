Корректировки обернуты в /* SADOVIKOW correction */

Отправляем доп поля в json:
- agent_info.type - Тип агента
- supplier_info.inn - ИНН комитента (поставщика, продавца)
- supplier_info.name - Юр. название комитента (поставщика, продавца)


<b>В основном вставлен этот код:</b>
```php
 'supplier_info' => array(
      array(
          'name' => 'agent_info.type',
          'value' => '6',
      ),
      array(
          'name' => 'supplier_info.inn',
          'value' => $arBasketItemsInfo[$BasketItemId]["PROPERTY_INN_KOMITENTA_VALUE"],
      ),
      array(
          'name' => 'supplier_info.name',
          'value' => $arBasketItemsInfo[$BasketItemId]["PROPERTY_KOMITENT_VALUE"],
      ),
      //array(
      //    'name' => 'supplier_info.phone',
      //    'value' => $arBasketItemsInfo[$BasketItemId]["PROPERTY_TELEFON_KOMITENTA_VALUE"],
     // ),
  )
```

PROPERTY подтягиваются из товаров заказа"

```php
 $basketItemsIds = Array();
 $arBasketItemsInfo = Array();
 foreach ($basketItems as $key => $BasketItem) {
     $basketItemsIds[$key] = $BasketItem->getProductId();
 }

 if(count($basketItemsIds > 0)) {
     $arraySize = count($basketItemsIds);
     $arSort   = Array('DATE_CREATE' => 'DESC');
     $arFilter = Array("ID" => $basketItemsIds, "ACTIVE"=>"Y");
     $navParams = Array("nPageSize"=>$arraySize);
     $arSelect = Array("ID", "IBLOCK_ID", "NAME", "DATE_ACTIVE_FROM", "PROPERTY_KOMITENT", "PROPERTY_TELEFON_KOMITENTA", "PROPERTY_INN_KOMITENTA");
     $dbFields = \CIBlockElement::GetList($arSort, $arFilter, false, $navParams, $arSelect);
     while($dbElement = $dbFields->GetNextElement())
     {
        $arFields = $dbElement->GetFields();
        $arBasketItemsInfo[$arFields["ID"]] = $arFields;
 }
``
