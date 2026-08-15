<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Imports Orange Cafe's real printed menu into the catalog. Each section
 * is routed to whichever kitchen printer actually prepares it — drink
 * sections to the 'مشروبات' station, food/dessert sections to 'ماكولات',
 * argileh to 'اراجيل' — matching the three printers already configured
 * for those stations in PrinterSeeder. No station in the PDF maps
 * one-to-one to "juices"; the request generalizes to "everything drunk
 * from a glass" vs "everything eaten with a fork" vs "argileh".
 *
 * Some sections price two tiers per item (pizza Small/Large, crepe-style
 * desserts Regular/Premium) at a uniform difference across every item in
 * that section — modeled as a shared modifier group per tier kind rather
 * than a flat price, so the size is a real selectable option on the
 * customer menu instead of two separate products.
 */
class OrangeMenuSeeder extends Seeder
{
    private const FOOD_STATION = 'ماكولات';

    private const DRINK_STATION = 'مشروبات';

    private const ARGILEH_STATION = 'اراجيل';

    public function run(): void
    {
        $menu = Menu::where('slug', 'main-menu')->first();

        if (! $menu) {
            return;
        }

        $pizzaSize = ModifierGroup::updateOrCreate(['id' => 3], [
            'is_required' => true, 'min_select' => 1, 'max_select' => 1, 'is_active' => true, 'sort_order' => 0,
            'ar' => ['name' => 'الحجم'], 'en' => ['name' => 'Size'],
        ]);
        $pizzaSize->modifiers()->delete();
        $pizzaSize->modifiers()->create(['price_cents' => 0, 'is_active' => true, 'sort_order' => 0, 'ar' => ['name' => 'صغير'], 'en' => ['name' => 'Small']]);
        $pizzaSize->modifiers()->create(['price_cents' => 1000, 'is_active' => true, 'sort_order' => 1, 'ar' => ['name' => 'كبير'], 'en' => ['name' => 'Large']]);

        $dessertTier = ModifierGroup::updateOrCreate(['id' => 4], [
            'is_required' => true, 'min_select' => 1, 'max_select' => 1, 'is_active' => true, 'sort_order' => 0,
            'ar' => ['name' => 'الفئة'], 'en' => ['name' => 'Tier'],
        ]);
        $dessertTier->modifiers()->delete();
        $dessertTier->modifiers()->create(['price_cents' => 0, 'is_active' => true, 'sort_order' => 0, 'ar' => ['name' => 'عادي'], 'en' => ['name' => 'Regular']]);
        $dessertTier->modifiers()->create(['price_cents' => 1000, 'is_active' => true, 'sort_order' => 1, 'ar' => ['name' => 'مميز'], 'en' => ['name' => 'Premium']]);

        $friesSize = ModifierGroup::updateOrCreate(['id' => 5], [
            'is_required' => true, 'min_select' => 1, 'max_select' => 1, 'is_active' => true, 'sort_order' => 0,
            'ar' => ['name' => 'الحجم'], 'en' => ['name' => 'Size'],
        ]);
        $friesSize->modifiers()->delete();
        $friesSize->modifiers()->create(['price_cents' => 0, 'is_active' => true, 'sort_order' => 0, 'ar' => ['name' => 'صغير'], 'en' => ['name' => 'Small']]);
        $friesSize->modifiers()->create(['price_cents' => 500, 'is_active' => true, 'sort_order' => 1, 'ar' => ['name' => 'كبير'], 'en' => ['name' => 'Large']]);

        $tierGroups = ['pizza' => $pizzaSize, 'dessert' => $dessertTier, 'fries' => $friesSize];

        foreach ($this->sections() as $sectionSortOrder => $section) {
            $category = Category::updateOrCreate(['slug' => $section['slug']], [
                'menu_id' => $menu->id, 'parent_id' => null, 'station' => $section['station'],
                'is_active' => true, 'sort_order' => 20 + $sectionSortOrder,
                'ar' => ['name' => $section['ar']], 'en' => ['name' => $section['en']],
            ]);

            foreach ($section['items'] as $itemSortOrder => $item) {
                $product = Product::updateOrCreate(['slug' => "{$section['slug']}-{$itemSortOrder}"], [
                    'price_cents' => $item['price'] * 100,
                    'is_active' => true,
                    'sort_order' => $itemSortOrder,
                    'ar' => ['name' => $item['ar']],
                    'en' => ['name' => $item['en']],
                ]);

                $product->modifierGroups()->sync(isset($item['tier']) ? [$tierGroups[$item['tier']]->id => ['sort_order' => 0]] : []);

                MenuItem::updateOrCreate(
                    ['category_id' => $category->id, 'product_id' => $product->id],
                    ['menu_id' => $menu->id, 'price_cents' => null, 'is_active' => true, 'is_featured' => false, 'sort_order' => $itemSortOrder],
                );
            }
        }
    }

    /**
     * @return array<int, array{slug: string, ar: string, en: string, station: string, items: array<int, array{ar: string, en: string, price: int, tier?: string}>}>
     */
    private function sections(): array
    {
        return [
            [
                'slug' => 'meals', 'ar' => 'وجبات', 'en' => 'Meals', 'station' => self::FOOD_STATION,
                'items' => [
                    ['ar' => 'شاورما عربي', 'en' => 'Arabic Shawarma', 'price' => 20],
                    ['ar' => 'مسحب دجاج', 'en' => 'Chicken Mashab', 'price' => 25],
                    ['ar' => 'اصابع دجاج مقلية', 'en' => 'Fried Chicken Fingers', 'price' => 25],
                    ['ar' => 'كرات دجاج مقلية', 'en' => 'Fried Chicken Balls', 'price' => 25],
                    ['ar' => 'اصابع جبنة مقلية', 'en' => 'Fried Cheese Sticks', 'price' => 25],
                    ['ar' => 'برجر لحمة طازج', 'en' => 'Fresh Beef Burger', 'price' => 30],
                    ['ar' => 'برجر دجاج طازج', 'en' => 'Fresh Chicken Burger', 'price' => 25],
                    ['ar' => 'فاهيتا دجاج', 'en' => 'Chicken Fajita', 'price' => 30],
                    ['ar' => 'اجنحة دجاج', 'en' => 'Chicken Wings', 'price' => 25],
                    ['ar' => 'جمبري بالقرشلة', 'en' => 'Breaded Shrimp', 'price' => 40],
                    ['ar' => 'كريسبي رول', 'en' => 'Crispy Roll', 'price' => 45],
                    ['ar' => 'شيش طاووق', 'en' => 'Shish Tawook', 'price' => 40],
                    ['ar' => 'كبة (4 قطع)', 'en' => 'Kibbeh (4 pcs)', 'price' => 25],
                    ['ar' => 'خبز بالثوم مع جبنة', 'en' => 'Garlic Bread with Cheese', 'price' => 20],
                    ['ar' => 'بطاطا شيبس', 'en' => 'French Fries', 'price' => 10, 'tier' => 'fries'],
                    ['ar' => 'بطاطا كيرلي', 'en' => 'Curly Fries', 'price' => 20],
                    ['ar' => 'بطاطا مكس مع جبنة', 'en' => 'Loaded Fries with Cheese', 'price' => 25],
                ],
            ],
            [
                'slug' => 'italian-dishes', 'ar' => 'وجبات ايطالية', 'en' => 'Italian Dishes', 'station' => self::FOOD_STATION,
                'items' => [
                    ['ar' => 'كوردن بلو', 'en' => 'Cordon Bleu', 'price' => 45],
                    ['ar' => 'ستيك دجاج', 'en' => 'Chicken Steak', 'price' => 40],
                    ['ar' => 'فوتوتشيني', 'en' => 'Fettuccine', 'price' => 30],
                    ['ar' => 'فوتوتشيني مع دجاج', 'en' => 'Fettuccine with Chicken', 'price' => 35],
                    ['ar' => 'فوتوتشيني مع جمبري', 'en' => 'Fettuccine with Shrimp', 'price' => 45],
                    ['ar' => 'جمبري مع ثوم', 'en' => 'Shrimp with Garlic', 'price' => 45],
                    ['ar' => 'سباغيتي', 'en' => 'Spaghetti', 'price' => 30],
                    ['ar' => 'سباغيتي مع لحمة', 'en' => 'Spaghetti with Meat', 'price' => 40],
                    ['ar' => 'لازانيا مع لحمة', 'en' => 'Lasagna with Meat', 'price' => 45],
                    ['ar' => 'لازانيا مع خضار', 'en' => 'Lasagna with Vegetables', 'price' => 35],
                ],
            ],
            [
                'slug' => 'fresh-salads', 'ar' => 'سلطات فرش', 'en' => 'Fresh Salads', 'station' => self::FOOD_STATION,
                'items' => [
                    ['ar' => 'جرجير', 'en' => 'Arugula', 'price' => 15],
                    ['ar' => 'سيزر', 'en' => 'Caesar', 'price' => 20],
                    ['ar' => 'سيزر مع دجاج', 'en' => 'Caesar with Chicken', 'price' => 25],
                    ['ar' => 'تبولة', 'en' => 'Tabbouleh', 'price' => 20],
                    ['ar' => 'فتوش', 'en' => 'Fattoush', 'price' => 20],
                    ['ar' => 'يونانية', 'en' => 'Greek Salad', 'price' => 25],
                    ['ar' => 'اورانج خاص', 'en' => 'Orange Special Salad', 'price' => 30],
                ],
            ],
            [
                'slug' => 'pizza', 'ar' => 'بيتزا', 'en' => 'Pizza', 'station' => self::FOOD_STATION,
                'items' => [
                    ['ar' => 'مارجريتا', 'en' => 'Margherita', 'price' => 15, 'tier' => 'pizza'],
                    ['ar' => 'ايطالية', 'en' => 'Italiana', 'price' => 20, 'tier' => 'pizza'],
                    ['ar' => 'خضار', 'en' => 'Vegetables', 'price' => 20, 'tier' => 'pizza'],
                    ['ar' => 'دجاج', 'en' => 'Chicken', 'price' => 25, 'tier' => 'pizza'],
                    ['ar' => 'اورانج خاص', 'en' => 'Orange Special', 'price' => 30, 'tier' => 'pizza'],
                ],
            ],
            [
                'slug' => 'soups', 'ar' => 'شوربات', 'en' => 'Soups', 'station' => self::FOOD_STATION,
                'items' => [
                    ['ar' => 'فطر طازج', 'en' => 'Fresh Mushroom', 'price' => 25],
                    ['ar' => 'خضار إيطالية', 'en' => 'Italian Vegetable', 'price' => 25],
                    ['ar' => 'جمبري', 'en' => 'Shrimp', 'price' => 30],
                ],
            ],
            [
                'slug' => 'crepe-cakes', 'ar' => 'حلويات', 'en' => 'Sweets', 'station' => self::FOOD_STATION,
                'items' => [
                    ['ar' => 'كريب', 'en' => 'Crepe', 'price' => 15, 'tier' => 'dessert'],
                    ['ar' => 'وافل', 'en' => 'Waffle', 'price' => 15, 'tier' => 'dessert'],
                    ['ar' => 'كيك', 'en' => 'Cake', 'price' => 15, 'tier' => 'dessert'],
                    ['ar' => 'كنافة اسطنبولية', 'en' => 'Istanbouli Kunafa', 'price' => 15, 'tier' => 'dessert'],
                ],
            ],
            [
                'slug' => 'natural-juices', 'ar' => 'عصائر طبيعية', 'en' => 'Natural Juices', 'station' => self::DRINK_STATION,
                'items' => [
                    ['ar' => 'برتقال', 'en' => 'Orange', 'price' => 10],
                    ['ar' => 'ليمون', 'en' => 'Lemon', 'price' => 10],
                    ['ar' => 'ليمون و نعنع', 'en' => 'Lemon & Mint', 'price' => 10],
                    ['ar' => 'مانجا', 'en' => 'Mango', 'price' => 10],
                    ['ar' => 'جوافا', 'en' => 'Guava', 'price' => 10],
                    ['ar' => 'تفاح', 'en' => 'Apple', 'price' => 10],
                    ['ar' => 'جزر', 'en' => 'Carrot', 'price' => 10],
                    ['ar' => 'فراولة', 'en' => 'Strawberry', 'price' => 15],
                    ['ar' => 'عصير مكس', 'en' => 'Mix Juice', 'price' => 15],
                ],
            ],
            [
                'slug' => 'cocktail-drinks', 'ar' => 'كوكتيل', 'en' => 'Cocktail', 'station' => self::DRINK_STATION,
                'items' => [
                    ['ar' => 'سادة', 'en' => 'Plain', 'price' => 10],
                    ['ar' => 'بوظة', 'en' => 'Ice Cream Cocktail', 'price' => 15],
                    ['ar' => 'فواكة', 'en' => 'Fruit Cocktail', 'price' => 15],
                    ['ar' => 'مكسرات', 'en' => 'Nuts Cocktail', 'price' => 15],
                    ['ar' => 'فخفخينا', 'en' => 'Fakhfakhina', 'price' => 20],
                    ['ar' => 'شامي', 'en' => 'Shami', 'price' => 20],
                    ['ar' => 'اورانج خاص', 'en' => 'Orange Special Cocktail', 'price' => 25],
                ],
            ],
            [
                'slug' => 'milkshakes', 'ar' => 'ميلك شيك', 'en' => 'Milkshake', 'station' => self::DRINK_STATION,
                'items' => [
                    ['ar' => 'شوكولاته', 'en' => 'Chocolate', 'price' => 15],
                    ['ar' => 'موز', 'en' => 'Banana', 'price' => 15],
                    ['ar' => 'فراولة', 'en' => 'Strawberry', 'price' => 15],
                    ['ar' => 'أوريو', 'en' => 'Oreo', 'price' => 15],
                    ['ar' => 'جوز هند', 'en' => 'Coconut', 'price' => 15],
                    ['ar' => 'فانيلا', 'en' => 'Vanilla', 'price' => 15],
                    ['ar' => 'لوتس', 'en' => 'Lotus', 'price' => 20],
                    ['ar' => 'نوتيلا', 'en' => 'Nutella', 'price' => 20],
                    ['ar' => 'سنيكرز', 'en' => 'Snickers', 'price' => 20],
                    ['ar' => 'سيريلاك', 'en' => 'Cerelac', 'price' => 20],
                    ['ar' => 'كت كات', 'en' => 'Kit Kat', 'price' => 20],
                    ['ar' => 'ابل باي', 'en' => 'Apple Pie', 'price' => 20],
                ],
            ],
            [
                'slug' => 'ice-cream-scoops', 'ar' => 'بوظة', 'en' => 'Ice Cream', 'station' => self::FOOD_STATION,
                'items' => [
                    ['ar' => 'شوكولاته', 'en' => 'Chocolate', 'price' => 10],
                    ['ar' => 'فانيلا', 'en' => 'Vanilla', 'price' => 10],
                    ['ar' => 'قشطة و كراميل', 'en' => 'Cream & Caramel', 'price' => 10],
                    ['ar' => 'حمضيات', 'en' => 'Citrus', 'price' => 10],
                    ['ar' => 'اوريو', 'en' => 'Oreo', 'price' => 15],
                    ['ar' => 'شامية', 'en' => 'Shamia', 'price' => 20],
                    ['ar' => 'اورانج خاص', 'en' => 'Orange Special Ice Cream', 'price' => 25],
                ],
            ],
            [
                'slug' => 'hot-drinks', 'ar' => 'مشروبات ساخنة', 'en' => 'Hot Drinks', 'station' => self::DRINK_STATION,
                'items' => [
                    ['ar' => 'اسبريسو', 'en' => 'Espresso', 'price' => 10],
                    ['ar' => 'امريكانو', 'en' => 'Americano', 'price' => 10],
                    ['ar' => 'موكا', 'en' => 'Mocha', 'price' => 15],
                    ['ar' => 'قهوة عربية', 'en' => 'Arabic Coffee', 'price' => 10],
                    ['ar' => 'لاتيه', 'en' => 'Latte', 'price' => 12],
                    ['ar' => 'هوت شوكليت', 'en' => 'Hot Chocolate', 'price' => 12],
                    ['ar' => 'شوكو', 'en' => 'Choco', 'price' => 10],
                    ['ar' => 'نسكافيه', 'en' => 'Nescafe', 'price' => 10],
                    ['ar' => 'كابتشينو', 'en' => 'Cappuccino', 'price' => 10],
                    ['ar' => 'فرنش بندق', 'en' => 'French Hazelnut', 'price' => 12],
                    ['ar' => 'فرنش كاراميل', 'en' => 'French Caramel', 'price' => 12],
                    ['ar' => 'فرنش فانيلا', 'en' => 'French Vanilla', 'price' => 12],
                    ['ar' => 'سحلب', 'en' => 'Sahlab', 'price' => 12],
                    ['ar' => 'اعشاب', 'en' => 'Herbal Tea', 'price' => 10],
                    ['ar' => 'لوتس', 'en' => 'Lotus', 'price' => 12],
                    ['ar' => 'نوتيلا', 'en' => 'Nutella', 'price' => 12],
                    ['ar' => 'شاي', 'en' => 'Tea', 'price' => 10],
                ],
            ],
            [
                'slug' => 'iced-drinks', 'ar' => 'مشروبات مثلجة', 'en' => 'Iced Drinks', 'station' => self::DRINK_STATION,
                'items' => [
                    ['ar' => 'كافيه', 'en' => 'Iced Coffee (Cafe)', 'price' => 15],
                    ['ar' => 'بسفلورا', 'en' => 'Passion Fruit', 'price' => 15],
                    ['ar' => 'موكا', 'en' => 'Iced Mocha', 'price' => 18],
                    ['ar' => 'شوكو', 'en' => 'Iced Choco', 'price' => 15],
                    ['ar' => 'شاي', 'en' => 'Iced Tea', 'price' => 15],
                    ['ar' => 'قهوة', 'en' => 'Iced Coffee', 'price' => 15],
                    ['ar' => 'فراولة', 'en' => 'Iced Strawberry', 'price' => 15],
                ],
            ],
            [
                'slug' => 'soda-drinks', 'ar' => 'مشروبات غازية', 'en' => 'Soft Drinks', 'station' => self::DRINK_STATION,
                'items' => [
                    ['ar' => 'سبرايت', 'en' => 'Sprite', 'price' => 5],
                    ['ar' => 'كولا', 'en' => 'Cola', 'price' => 5],
                    ['ar' => 'صودا', 'en' => 'Soda', 'price' => 8],
                    ['ar' => 'بفاريا', 'en' => 'Bavaria', 'price' => 8],
                    ['ar' => 'XL', 'en' => 'XL', 'price' => 8],
                    ['ar' => 'بلو', 'en' => 'Blu', 'price' => 8],
                    ['ar' => 'مياه', 'en' => 'Water', 'price' => 5],
                ],
            ],
            [
                'slug' => 'avocado-cocktail', 'ar' => 'كوكتيل افوكادو', 'en' => 'Avocado Cocktail', 'station' => self::DRINK_STATION,
                'items' => [
                    ['ar' => 'افوكادو وموز', 'en' => 'Avocado & Banana', 'price' => 18],
                    ['ar' => 'افوكادو ومانجا', 'en' => 'Avocado & Mango', 'price' => 18],
                    ['ar' => 'افوكادو وتمر', 'en' => 'Avocado & Date', 'price' => 20],
                    ['ar' => 'افوكادو بالمكسرات', 'en' => 'Avocado with Nuts', 'price' => 20],
                ],
            ],
            [
                'slug' => 'mojito-soda', 'ar' => 'موهيتو / صودا', 'en' => 'Mojito / Soda', 'station' => self::DRINK_STATION,
                'items' => [
                    ['ar' => 'كيوي', 'en' => 'Kiwi', 'price' => 15],
                    ['ar' => 'ليمون', 'en' => 'Lemon', 'price' => 15],
                    ['ar' => 'بطيخ', 'en' => 'Watermelon', 'price' => 15],
                    ['ar' => 'خوخ', 'en' => 'Peach', 'price' => 15],
                    ['ar' => 'اناناس', 'en' => 'Pineapple', 'price' => 15],
                    ['ar' => 'ليتشي', 'en' => 'Lychee', 'price' => 15],
                ],
            ],
            [
                'slug' => 'اراجيل', 'ar' => 'اراجيل', 'en' => 'Argileh', 'station' => self::ARGILEH_STATION,
                'items' => [
                    ['ar' => 'تفاحتين عادي', 'en' => 'Double Apple', 'price' => 25],
                    ['ar' => 'ليمون و نعنع', 'en' => 'Lemon & Mint', 'price' => 25],
                    ['ar' => 'بطيخ و نعنع', 'en' => 'Watermelon & Mint', 'price' => 25],
                    ['ar' => 'بلوبيري', 'en' => 'Blueberry', 'price' => 25],
                    ['ar' => 'سهم و نعنع', 'en' => 'Sahm & Mint', 'price' => 25],
                    ['ar' => 'سهم و قرفة', 'en' => 'Sahm & Cinnamon', 'price' => 25],
                    ['ar' => 'توت وعنب', 'en' => 'Berry & Grape', 'price' => 25],
                    ['ar' => 'كاندي دروبس', 'en' => 'Candy Drops', 'price' => 25],
                    ['ar' => 'موهيتو', 'en' => 'Mojito', 'price' => 25],
                    ['ar' => 'Love66', 'en' => 'Love66', 'price' => 25],
                    ['ar' => 'نخلة', 'en' => 'Nakhla', 'price' => 30],
                    ['ar' => 'فريش', 'en' => 'Fresh', 'price' => 30],
                ],
            ],
        ];
    }
}
