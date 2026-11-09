<?php
declare(strict_types=1);
/** Home delivery orders, assignment and secure rider/customer location tracking. */
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/utils.php';

function delivery_enabled(PDO $pdo): bool { $row = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='home_delivery_enabled' LIMIT 1")->fetch(); return $row && $row['setting_value'] === '1'; }
function coordinate($value, float $min, float $max): ?float { if ($value === null || $value === '') return null; $number = filter_var($value, FILTER_VALIDATE_FLOAT); return $number !== false && $number >= $min && $number <= $max ? (float)$number : null; }
function delivery_row(PDO $pdo, int $id, array $auth): ?array { $scope = demo_data_scope_sql('d', $auth); $userScope = demo_data_scope_sql('c', $auth); $riderScope = demo_data_scope_sql('r', $auth); $stmt = $pdo->prepare("SELECT d.*, c.name AS customer_name, c.email AS customer_email, r.name AS rider_name FROM delivery_orders d JOIN users c ON c.id=d.customer_id AND {$userScope} LEFT JOIN users r ON r.id=d.rider_id AND {$riderScope} WHERE d.id=:id AND {$scope} LIMIT 1"); $stmt->execute([':id'=>$id]); return $stmt->fetch() ?: null; }

$pdo = get_db(); $auth = require_auth(); $method = $_SERVER['REQUEST_METHOD'];
if (!delivery_enabled($pdo) && empty($auth['is_demo'])) json_response(['error' => 'Home delivery is currently unavailable'], 403);

if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $order = delivery_row($pdo, $id, $auth); if (!$order) json_response(['error'=>'Delivery order not found'],404);
        if (!in_array($auth['role'], ['admin','owner','manager','supervisor'], true) && (int)$order['customer_id'] !== $auth['id'] && (int)$order['rider_id'] !== $auth['id']) json_response(['error'=>'Forbidden'],403);
        $track = $pdo->prepare('SELECT latitude,longitude,accuracy_meters,recorded_at FROM delivery_tracking WHERE delivery_order_id=:id AND ' . demo_data_scope_sql('delivery_tracking', $auth) . ' ORDER BY recorded_at DESC LIMIT 1'); $track->execute([':id'=>$id]);
        $order['latest_location'] = $track->fetch() ?: null; json_response(['success'=>true,'data'=>$order]);
    }
    $scope = demo_data_scope_sql('d', $auth); $userScope = demo_data_scope_sql('c', $auth); $riderScope = demo_data_scope_sql('r', $auth);
    $sql = "SELECT d.*, c.name AS customer_name, r.name AS rider_name FROM delivery_orders d JOIN users c ON c.id=d.customer_id AND {$userScope} LEFT JOIN users r ON r.id=d.rider_id AND {$riderScope} WHERE {$scope}"; $params=[];
    if ($auth['role'] === 'customer') { $sql.=' AND d.customer_id=:user'; $params[':user']=$auth['id']; }
    elseif ($auth['role'] === 'rider') { $sql.=' AND (d.rider_id=:user OR d.rider_id IS NULL)'; $params[':user']=$auth['id']; }
    elseif (!in_array($auth['role'], ['admin','owner','manager','supervisor'], true)) json_response(['error'=>'Forbidden'],403);
    $sql.=' ORDER BY d.created_at DESC LIMIT 100'; $stmt=$pdo->prepare($sql); $stmt->execute($params); json_response(['success'=>true,'data'=>['orders'=>$stmt->fetchAll()]]);
}

if ($method !== 'POST') json_response(['error'=>'Method not allowed'],405);
try { $body=get_json_body(); } catch (JsonException $e) { json_response(['error'=>'Invalid JSON body'],400); }
$action=(string)($body['action'] ?? 'create'); $now=date('Y-m-d H:i:s');
if ($action === 'create') {
    if ($auth['role'] !== 'customer') json_response(['error'=>'Only verified customers can request delivery'],403);
    $verified=$pdo->prepare('SELECT phone_number FROM customer_profiles WHERE user_id=:id AND email_verified_at IS NOT NULL'); $verified->execute([':id'=>$auth['id']]); $profile=$verified->fetch(); if (!$profile) json_response(['error'=>'Verify your email before requesting delivery'],403);
    $items=$body['items'] ?? []; $address=trim((string)($body['delivery_address'] ?? '')); $phone=trim((string)($body['phone_number'] ?? $profile['phone_number']));
    if (!is_array($items) || !$items || $address==='' || $phone==='') json_response(['error'=>'Items, delivery address and phone number are required'],422);
    $ids=[]; foreach($items as $item){$id=(int)($item['menu_item_id']??0);$qty=(int)($item['quantity']??0);if($id<=0||$qty<1||$qty>100)json_response(['error'=>'Invalid delivery item'],422);$ids[]=$id;}
    $marks=implode(',',array_fill(0,count($ids),'?'));$menu=$pdo->prepare("SELECT id,name,price FROM menu_items WHERE id IN ($marks) AND available=1");$menu->execute($ids);$rows=$menu->fetchAll();if(count($rows)!==count(array_unique($ids)))json_response(['error'=>'One or more items are unavailable'],422);$lookup=[];foreach($rows as $row)$lookup[(int)$row['id']]=$row;
    $safe=[];$total=0.0;foreach($items as $item){$id=(int)$item['menu_item_id'];$qty=(int)$item['quantity'];$safe[]=['menu_item_id'=>$id,'name'=>$lookup[$id]['name'],'quantity'=>$qty,'unit_price'=>(float)$lookup[$id]['price'],'instructions'=>trim((string)($item['instructions']??''))];$total += $qty*(float)$lookup[$id]['price'];}
    $lat=coordinate($body['latitude']??null,-90,90);$lng=coordinate($body['longitude']??null,-180,180);if(($body['latitude']??null)!==null&&($lat===null||$lng===null))json_response(['error'=>'Invalid delivery coordinates'],422);
    $basePrice = (float)($pdo->query("SELECT setting_value FROM settings WHERE setting_key='delivery_base_price' LIMIT 1")->fetchColumn() ?: 0);
    $perKmRate = (float)($pdo->query("SELECT setting_value FROM settings WHERE setting_key='delivery_per_km_rate' LIMIT 1")->fetchColumn() ?: 0);
    $maxDistance = (float)($pdo->query("SELECT setting_value FROM settings WHERE setting_key='delivery_max_distance' LIMIT 1")->fetchColumn() ?: 50);
    if ($basePrice > 0 || $perKmRate > 0) {
        $distance = 0.0;
        if ($lat !== null && $lng !== null) {
            $restaurantStmt = $pdo->query("SELECT latitude, longitude FROM settings WHERE setting_key='restaurant_location' LIMIT 1");
            $restaurant = $restaurantStmt->fetch();
            if ($restaurant && $restaurant['latitude'] !== null && $restaurant['longitude'] !== null) {
                $rLat = (float)$restaurant['latitude'];
                $rLng = (float)$restaurant['longitude'];
                $distance = sqrt(pow($lat - $rLat, 2) + pow($lng - $rLng, 2)) * 111.0;
                if ($distance > $maxDistance) {
                    json_response(['error' => "Delivery distance ({round($distance, 1)}km) exceeds maximum allowed ({$maxDistance}km)"], 422);
                }
            }
        }
        $deliveryFee = $basePrice + ($perKmRate * max(0, $distance));
        $total += $deliveryFee;
    }
    $stmt=$pdo->prepare('INSERT INTO delivery_orders (customer_id,items_json,total_amount,delivery_address,phone_number,alternate_phone_number,special_requests,latitude,longitude,payment_method,restaurant_id,created_at,updated_at) VALUES (:customer,:items,:total,:address,:phone,:alternate,:requests,:lat,:lng,:payment,:restaurant,:now,:now)');
    $stmt->execute([':customer'=>$auth['id'],':items'=>json_encode($safe),':total'=>$total,':address'=>$address,':phone'=>$phone,':alternate'=>trim((string)($body['alternate_phone_number']??''))?:null,':requests'=>trim((string)($body['special_requests']??''))?:null,':lat'=>$lat,':lng'=>$lng,':payment'=>in_array(($body['payment_method']??'pending'),['cash','pos','transfer','pending'],true)?$body['payment_method']:'pending',':restaurant'=>$auth['restaurant_id']??null,':now'=>$now]);
    json_response(['success'=>true,'data'=>delivery_row($pdo,(int)$pdo->lastInsertId(),$auth)],201);
}
if ($action === 'assign' || $action === 'status') {
    if (!in_array($auth['role'],['rider','admin','owner','manager','supervisor'],true))json_response(['error'=>'Forbidden'],403);$id=(int)($body['id']??0);$order=delivery_row($pdo,$id,$auth);if(!$order)json_response(['error'=>'Delivery order not found'],404);
    if($action==='assign'){if($auth['role']==='rider'){$rider=$auth['id'];}else{$rider=(int)($body['rider_id']??0);}if($rider<=0)json_response(['error'=>'Rider is required'],422);$riderScope=demo_data_scope_sql('users',$auth);$riderCheck=$pdo->prepare("SELECT id FROM users WHERE id=:rider AND role='rider' AND {$riderScope}");$riderCheck->execute([':rider'=>$rider]);if(!$riderCheck->fetch())json_response(['error'=>'Rider not found'],404);$pdo->prepare("UPDATE delivery_orders SET rider_id=:rider,status='accepted',updated_at=:now WHERE id=:id AND " . demo_data_scope_sql('delivery_orders',$auth))->execute([':rider'=>$rider,':now'=>$now,':id'=>$id]);}
    else {$status=(string)($body['status']??'');if(!in_array($status,['accepted','preparing','out_for_delivery','delivered','cancelled'],true))json_response(['error'=>'Invalid delivery status'],422);if($auth['role']==='rider'&&(int)$order['rider_id']!==$auth['id'])json_response(['error'=>'Delivery is not assigned to this rider'],403);$pdo->prepare('UPDATE delivery_orders SET status=:status,updated_at=:now WHERE id=:id AND ' . demo_data_scope_sql('delivery_orders',$auth))->execute([':status'=>$status,':now'=>$now,':id'=>$id]);}json_response(['success'=>true,'data'=>delivery_row($pdo,$id,$auth)]);
}
if ($action === 'location') {
    if($auth['role']!=='rider')json_response(['error'=>'Only riders can update location'],403);$id=(int)($body['id']??0);$order=delivery_row($pdo,$id,$auth);if(!$order||(int)$order['rider_id']!==$auth['id'])json_response(['error'=>'Delivery is not assigned to this rider'],403);$lat=coordinate($body['latitude']??null,-90,90);$lng=coordinate($body['longitude']??null,-180,180);if($lat===null||$lng===null)json_response(['error'=>'Valid latitude and longitude are required'],422);$pdo->prepare('INSERT INTO delivery_tracking (delivery_order_id,rider_id,latitude,longitude,accuracy_meters,demo_owner_id,restaurant_id,recorded_at) VALUES (:delivery,:rider,:lat,:lng,:accuracy,NULL,:restaurant,:now)')->execute([':delivery'=>$id,':rider'=>$auth['id'],':lat'=>$lat,':lng'=>$lng,':accuracy'=>coordinate($body['accuracy_meters']??null,0,100000),':restaurant'=>$auth['restaurant_id']??null,':now'=>$now]);json_response(['success'=>true,'data'=>['message'=>'Location updated']]);
}
json_response(['error'=>'Unsupported action'],400);
