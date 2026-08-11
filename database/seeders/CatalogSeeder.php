<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Tags
        $tagPopular = Tag::updateOrCreate(['slug' => 'popular'], [
            'icon' => '⭐', 'is_active' => true, 'sort_order' => 0,
            'ar' => ['name' => 'الأكثر طلباً'], 'en' => ['name' => 'Popular'],
        ]);
        $tagSpicy = Tag::updateOrCreate(['slug' => 'spicy'], [
            'icon' => '🌶️', 'is_active' => true, 'sort_order' => 1,
            'ar' => ['name' => 'حار'], 'en' => ['name' => 'Spicy'],
        ]);
        $tagVegetarian = Tag::updateOrCreate(['slug' => 'vegetarian'], [
            'icon' => '🌱', 'is_active' => true, 'sort_order' => 2,
            'ar' => ['name' => 'نباتي'], 'en' => ['name' => 'Vegetarian'],
        ]);
        $tagNew = Tag::updateOrCreate(['slug' => 'new'], [
            'icon' => '🆕', 'is_active' => true, 'sort_order' => 3,
            'ar' => ['name' => 'جديد'], 'en' => ['name' => 'New'],
        ]);

        // Modifier groups
        $sizeGroup = ModifierGroup::updateOrCreate(['id' => 1], [
            'is_required' => true, 'min_select' => 1, 'max_select' => 1, 'is_active' => true, 'sort_order' => 0,
            'ar' => ['name' => 'الحجم'], 'en' => ['name' => 'Size'],
        ]);
        $sizeGroup->modifiers()->delete();
        $regular = $sizeGroup->modifiers()->create(['price_cents' => 0, 'is_active' => true, 'sort_order' => 0, 'ar' => ['name' => 'عادي'], 'en' => ['name' => 'Regular']]);
        $large = $sizeGroup->modifiers()->create(['price_cents' => 500, 'is_active' => true, 'sort_order' => 1, 'ar' => ['name' => 'كبير'], 'en' => ['name' => 'Large']]);

        $extrasGroup = ModifierGroup::updateOrCreate(['id' => 2], [
            'is_required' => false, 'min_select' => 0, 'max_select' => 3, 'is_active' => true, 'sort_order' => 1,
            'ar' => ['name' => 'إضافات'], 'en' => ['name' => 'Extras'],
        ]);
        $extrasGroup->modifiers()->delete();
        $extrasGroup->modifiers()->create(['price_cents' => 150, 'is_active' => true, 'sort_order' => 0, 'ar' => ['name' => 'جبنة إضافية'], 'en' => ['name' => 'Extra Cheese']]);
        $extrasGroup->modifiers()->create(['price_cents' => 100, 'is_active' => true, 'sort_order' => 1, 'ar' => ['name' => 'صلصة إضافية'], 'en' => ['name' => 'Extra Sauce']]);
        $extrasGroup->modifiers()->create(['price_cents' => 0, 'is_active' => true, 'sort_order' => 2, 'ar' => ['name' => 'بدون بصل'], 'en' => ['name' => 'No Onions']]);

        // Categories: Main Dishes (parent) -> Grills, Pasta (subcategories)
        $mains = Category::updateOrCreate(['slug' => 'main-dishes'], [
            'parent_id' => null, 'is_active' => true, 'sort_order' => 0,
            'ar' => ['name' => 'الأطباق الرئيسية', 'description' => 'أطباقنا الرئيسية المميزة'],
            'en' => ['name' => 'Main Dishes', 'description' => 'Our signature main dishes'],
        ]);

        $grills = Category::updateOrCreate(['slug' => 'grills'], [
            'parent_id' => $mains->id, 'station' => 'grill', 'is_active' => true, 'sort_order' => 0,
            'ar' => ['name' => 'المشاوي'], 'en' => ['name' => 'Grills'],
        ]);

        $pasta = Category::updateOrCreate(['slug' => 'pasta'], [
            'parent_id' => $mains->id, 'station' => 'kitchen', 'is_active' => true, 'sort_order' => 1,
            'ar' => ['name' => 'المعكرونة'], 'en' => ['name' => 'Pasta'],
        ]);

        $drinks = Category::updateOrCreate(['slug' => 'beverages'], [
            'parent_id' => null, 'station' => 'bar', 'is_active' => true, 'sort_order' => 1,
            'ar' => ['name' => 'المشروبات', 'description' => 'مشروبات ساخنة وباردة'],
            'en' => ['name' => 'Beverages', 'description' => 'Hot and cold drinks'],
        ]);

        $desserts = Category::updateOrCreate(['slug' => 'desserts'], [
            'parent_id' => null, 'station' => 'dessert', 'is_active' => true, 'sort_order' => 2,
            'ar' => ['name' => 'الحلويات'], 'en' => ['name' => 'Desserts'],
        ]);

        // Products
        $chicken = Product::updateOrCreate(['slug' => 'grilled-chicken'], [
            'category_id' => $grills->id, 'price_cents' => 3500, 'is_active' => true, 'sort_order' => 0,
            'ar' => ['name' => 'دجاج مشوي', 'description' => 'دجاج مشوي مع الأرز والسلطة'],
            'en' => ['name' => 'Grilled Chicken', 'description' => 'Grilled chicken served with rice and salad'],
        ]);
        $chicken->modifierGroups()->sync([$sizeGroup->id => ['sort_order' => 0], $extrasGroup->id => ['sort_order' => 1]]);
        $chicken->tags()->sync([$tagPopular->id]);

        $kebab = Product::updateOrCreate(['slug' => 'beef-kebab'], [
            'category_id' => $grills->id, 'price_cents' => 4200, 'is_active' => true, 'sort_order' => 1,
            'ar' => ['name' => 'كباب لحم', 'description' => 'كباب لحم بقري مشوي على الفحم'],
            'en' => ['name' => 'Beef Kebab', 'description' => 'Charcoal-grilled beef kebab'],
        ]);
        $kebab->modifierGroups()->sync([$sizeGroup->id => ['sort_order' => 0]]);
        $kebab->tags()->sync([$tagPopular->id, $tagSpicy->id]);

        $alfredo = Product::updateOrCreate(['slug' => 'chicken-alfredo'], [
            'category_id' => $pasta->id, 'price_cents' => 3900, 'is_active' => true, 'sort_order' => 0,
            'ar' => ['name' => 'باستا ألفريدو بالدجاج', 'description' => 'معكرونة بصلصة الكريمة والدجاج'],
            'en' => ['name' => 'Chicken Alfredo', 'description' => 'Pasta in creamy sauce with grilled chicken'],
        ]);

        $juice = Product::updateOrCreate(['slug' => 'orange-juice'], [
            'category_id' => $drinks->id, 'price_cents' => 1200, 'is_active' => true, 'sort_order' => 0,
            'ar' => ['name' => 'عصير برتقال', 'description' => 'عصير برتقال طازج'],
            'en' => ['name' => 'Orange Juice', 'description' => 'Freshly squeezed orange juice'],
        ]);
        $juice->tags()->sync([$tagVegetarian->id]);

        Product::updateOrCreate(['slug' => 'iced-coffee'], [
            'category_id' => $drinks->id, 'price_cents' => 1500, 'is_active' => true, 'sort_order' => 1,
            'ar' => ['name' => 'قهوة مثلجة', 'description' => 'قهوة باردة مع الحليب'],
            'en' => ['name' => 'Iced Coffee', 'description' => 'Cold brew coffee with milk'],
        ]);

        $baklava = Product::updateOrCreate(['slug' => 'baklava'], [
            'category_id' => $desserts->id, 'price_cents' => 1800, 'is_active' => true, 'sort_order' => 0,
            // English translation intentionally omitted here to demonstrate
            // the admin "missing translation" indicator and the fallback
            // to Arabic on the customer menu / API.
            'ar' => ['name' => 'بقلاوة', 'description' => 'بقلاوة محشوة بالفستق الحلبي'],
        ]);
        $baklava->tags()->sync([$tagNew->id, $tagVegetarian->id]);
    }
}
