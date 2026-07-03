<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';

$allowedCategories = ['beer', 'malt', 'soft-drinks', 'water', 'energy-drinks', 'juice', 'spirits', 'ready-to-drink', 'rice', 'pepper-soup', 'grills', 'soups', 'swallow', 'extras'];
$category = in_array($_GET['category'] ?? '', $allowedCategories, true) ? $_GET['category'] : null;

$fallbackItems = [
    ['id' => 101, 'name' => 'Star Lager', 'description' => 'Chilled Nigerian lager with a crisp finish.', 'price' => 700.00, 'category' => 'beer'],
    ['id' => 102, 'name' => 'Hero Lager', 'description' => 'Light-bodied lager with a smooth taste.', 'price' => 650.00, 'category' => 'beer'],
    ['id' => 103, 'name' => 'Gulder', 'description' => 'Popular Nigerian beer served cold.', 'price' => 750.00, 'category' => 'beer'],
    ['id' => 104, 'name' => 'Amstel Malta', 'description' => 'Sweet non-alcoholic malt drink.', 'price' => 450.00, 'category' => 'malt'],
    ['id' => 105, 'name' => 'Guinness Malta', 'description' => 'Rich malt beverage with a distinctive aroma.', 'price' => 500.00, 'category' => 'malt'],
    ['id' => 106, 'name' => 'Coca-Cola', 'description' => 'Classic cola soda served ice cold.', 'price' => 350.00, 'category' => 'soft-drinks'],
    ['id' => 107, 'name' => 'Sprite', 'description' => 'Lemon-lime soda with crisp refreshment.', 'price' => 350.00, 'category' => 'soft-drinks'],
    ['id' => 108, 'name' => 'Eva', 'description' => 'Purified drinking water in a convenient bottle.', 'price' => 200.00, 'category' => 'water'],
    ['id' => 109, 'name' => 'Aquafina', 'description' => 'Pure bottled water with a clean taste.', 'price' => 220.00, 'category' => 'water'],
    ['id' => 110, 'name' => 'Fearless', 'description' => 'Energy drink for a fast energy boost.', 'price' => 900.00, 'category' => 'energy-drinks'],
    ['id' => 111, 'name' => 'Predator', 'description' => 'Sharp energy tonic for peak performance.', 'price' => 950.00, 'category' => 'energy-drinks'],
    ['id' => 112, 'name' => 'Five Alive', 'description' => 'Mixed fruit juice packed with tropical flavour.', 'price' => 600.00, 'category' => 'juice'],
    ['id' => 113, 'name' => 'Chi Exotic', 'description' => 'Exotic fruit juice with natural sweetness.', 'price' => 650.00, 'category' => 'juice'],
    ['id' => 114, 'name' => 'Jameson', 'description' => 'Irish whiskey with rich caramel and vanilla notes.', 'price' => 9500.00, 'category' => 'spirits'],
    ['id' => 115, 'name' => 'Black Label', 'description' => 'Premium blended Scotch whisky.', 'price' => 9000.00, 'category' => 'spirits'],
    ['id' => 116, 'name' => 'Smirnoff Ice', 'description' => 'Ready-to-drink malt beverage with fruity flavour.', 'price' => 950.00, 'category' => 'ready-to-drink'],
    ['id' => 117, 'name' => 'Bacardi Breezer', 'description' => 'Flavoured ready-to-drink pack for easy refreshment.', 'price' => 1000.00, 'category' => 'ready-to-drink'],
    ['id' => 201, 'name' => 'Jollof Rice', 'description' => 'Classic Nigerian jollof served hot and aromatic.', 'price' => 2400.00, 'category' => 'rice'],
    ['id' => 202, 'name' => 'Fried Rice', 'description' => 'Savory fried rice with vegetables and seasoning.', 'price' => 2500.00, 'category' => 'rice'],
    ['id' => 203, 'name' => 'White Rice', 'description' => 'Plain fluffy rice served with your choice of stew.', 'price' => 2200.00, 'category' => 'rice'],
    ['id' => 204, 'name' => 'Coconut Rice', 'description' => 'Rich coconut rice with a mild, fragrant finish.', 'price' => 2600.00, 'category' => 'rice'],
    ['id' => 301, 'name' => 'Goat Meat Pepper Soup', 'description' => 'Spicy pepper soup with tender goat meat.', 'price' => 3200.00, 'category' => 'pepper-soup'],
    ['id' => 302, 'name' => 'Cow Tail Pepper Soup', 'description' => 'Hearty cow tail pepper soup with bold seasoning.', 'price' => 3400.00, 'category' => 'pepper-soup'],
    ['id' => 303, 'name' => 'Catfish Pepper Soup', 'description' => 'Hot pepper soup made with fresh catfish.', 'price' => 3600.00, 'category' => 'pepper-soup'],
    ['id' => 304, 'name' => 'Chicken Pepper Soup', 'description' => 'Comforting pepper soup with tender chicken pieces.', 'price' => 3000.00, 'category' => 'pepper-soup'],
    ['id' => 305, 'name' => 'Assorted Meat Pepper Soup', 'description' => 'Pepper soup with assorted meats and spices.', 'price' => 3500.00, 'category' => 'pepper-soup'],
    ['id' => 401, 'name' => 'Catfish Grill', 'description' => 'Smoked catfish served with pepper sauce.', 'price' => 4200.00, 'category' => 'grills'],
    ['id' => 402, 'name' => 'Ram Meat', 'description' => 'Rich grilled ram meat with spicy seasoning.', 'price' => 4800.00, 'category' => 'grills'],
    ['id' => 403, 'name' => 'Suya', 'description' => 'Spiced grilled meat skewers with paprika flavour.', 'price' => 2800.00, 'category' => 'grills'],
    ['id' => 404, 'name' => 'Chicken Grill', 'description' => 'Grilled chicken served with a tangy sauce.', 'price' => 3200.00, 'category' => 'grills'],
    ['id' => 405, 'name' => 'Turkey Grill', 'description' => 'Tender turkey grilled to a golden finish.', 'price' => 3600.00, 'category' => 'grills'],
    ['id' => 406, 'name' => 'Gizzard', 'description' => 'Well-seasoned grilled gizzard with spicy sauce.', 'price' => 2200.00, 'category' => 'grills'],
    ['id' => 501, 'name' => 'Egusi Soup', 'description' => 'Thick egusi soup with assorted meat.', 'price' => 3000.00, 'category' => 'soups'],
    ['id' => 502, 'name' => 'Ogbono Soup', 'description' => 'Creamy ogbono soup with fresh ingredients.', 'price' => 3000.00, 'category' => 'soups'],
    ['id' => 503, 'name' => 'Vegetable Soup', 'description' => 'Healthy vegetable soup with rich seasoning.', 'price' => 2600.00, 'category' => 'soups'],
    ['id' => 504, 'name' => 'Okra Soup', 'description' => 'Classic okra soup with a smooth texture.', 'price' => 2800.00, 'category' => 'soups'],
    ['id' => 505, 'name' => 'Oha Soup', 'description' => 'Traditional oha soup with leafy greens.', 'price' => 3100.00, 'category' => 'soups'],
    ['id' => 601, 'name' => 'Pounded Yam', 'description' => 'Smooth pounded yam served hot.', 'price' => 1800.00, 'category' => 'swallow'],
    ['id' => 602, 'name' => 'Eba', 'description' => 'Soft eba with a neat, satisfying texture.', 'price' => 1600.00, 'category' => 'swallow'],
    ['id' => 603, 'name' => 'Semovita', 'description' => 'Soft semovita served in a warm bowl.', 'price' => 1700.00, 'category' => 'swallow'],
    ['id' => 604, 'name' => 'Amala', 'description' => 'Traditional amala with rich flavour.', 'price' => 1800.00, 'category' => 'swallow'],
    ['id' => 701, 'name' => 'Plantain', 'description' => 'Fried ripe plantain served as a side.', 'price' => 1200.00, 'category' => 'extras'],
    ['id' => 702, 'name' => 'Moi Moi', 'description' => 'Steamed bean pudding with pepper sauce.', 'price' => 1400.00, 'category' => 'extras'],
    ['id' => 703, 'name' => 'Coleslaw', 'description' => 'Fresh coleslaw served cold.', 'price' => 800.00, 'category' => 'extras'],
    ['id' => 704, 'name' => 'Salad', 'description' => 'Fresh salad with greens and vegetables.', 'price' => 900.00, 'category' => 'extras'],
    ['id' => 705, 'name' => 'French Fries', 'description' => 'Crispy fries served hot and salted.', 'price' => 1000.00, 'category' => 'extras'],
];

$items = $fallbackItems;
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $pdo = get_db();
        $query = 'SELECT id, name, description, price, category, available FROM menu_items WHERE available = 1';
        $params = [];
        if ($category !== null) {
            $query .= ' AND category = :category';
            $params[':category'] = $category;
        }
        $query .= ' ORDER BY category, name';
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $items = $stmt->fetchAll();
    } catch (Throwable $exception) {
        $items = $fallbackItems;
    }
    if ($category !== null && is_array($items)) {
        $items = array_values(array_filter($items, static function (array $item) use ($category): bool {
            return ($item['category'] ?? '') === $category;
        }));
    }
    if (empty($items)) {
        $items = $fallbackItems;
        if ($category !== null) {
            $items = array_values(array_filter($items, static function (array $item) use ($category): bool {
                return ($item['category'] ?? '') === $category;
            }));
        }
    }
    json_response(['items' => $items]);
    return;
}

if ($method === 'POST' || $method === 'PUT') {
    require_once __DIR__ . '/../../includes/utils.php';
    require_admin();

    try {
        $body = get_json_body();
    } catch (JsonException $exception) {
        json_response(['error' => 'Invalid JSON body'], 400);
    }

    try {
        $pdo = get_db();
        if ($method === 'POST') {
            $name = trim((string)($body['name'] ?? ''));
            $description = trim((string)($body['description'] ?? ''));
            $price = isset($body['price']) ? (float)$body['price'] : 0.0;
            $category = trim((string)($body['category'] ?? ''));
            $available = isset($body['available']) ? (int)$body['available'] : 1;
            $allowedCategories = ['beer', 'malt', 'soft-drinks', 'water', 'energy-drinks', 'juice', 'spirits', 'ready-to-drink', 'rice', 'pepper-soup', 'grills', 'soups', 'swallow', 'extras'];

            if ($name === '' || $description === '' || $price <= 0 || $category === '' || !in_array($category, $allowedCategories, true)) {
                json_response(['error' => 'Name, description, price and a valid category are required'], 400);
            }

            $insert = $pdo->prepare('INSERT INTO menu_items (name, description, price, category, available, created_at) VALUES (:name, :description, :price, :category, :available, NOW())');
            $insert->execute([
                ':name' => $name,
                ':description' => $description,
                ':price' => $price,
                ':category' => $category,
                ':available' => $available,
            ]);
            json_response(['success' => true, 'item_id' => (int)$pdo->lastInsertId()]);
            return;
        }

        if ($method === 'PUT') {
            $itemId = isset($body['id']) ? (int)$body['id'] : 0;
            $price = isset($body['price']) ? (float)$body['price'] : -1;
            if ($itemId <= 0 || $price < 0) {
                json_response(['error' => 'Item ID and price are required'], 400);
            }
            $update = $pdo->prepare('UPDATE menu_items SET price = :price WHERE id = :id');
            $update->execute([':price' => $price, ':id' => $itemId]);
            json_response(['success' => true]);
            return;
        }
    } catch (Throwable $exception) {
        json_response(['error' => 'Unable to update menu item'], 500);
    }
}

http_response_code(405);
json_response(['error' => 'Method not allowed'], 405);

if ($category !== null && is_array($items)) {
    $items = array_values(array_filter($items, static function (array $item) use ($category): bool {
        return ($item['category'] ?? '') === $category;
    }));
}

if (empty($items)) {
    $items = $fallbackItems;
    if ($category !== null) {
        $items = array_values(array_filter($items, static function (array $item) use ($category): bool {
            return ($item['category'] ?? '') === $category;
        }));
    }
}

json_response(['items' => $items]);
