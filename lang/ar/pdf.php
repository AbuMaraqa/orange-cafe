<?php

return [

    // Printed beside each dish on the kitchen ticket.
    'minutes' => 'دقيقة',
    'invoice' => 'فاتورة',
    'date' => 'التاريخ',
    'table' => 'طاولة',
    'guests' => 'عدد الضيوف',
    'customer' => 'الزبون',
    'item' => 'الصنف',
    'qty' => 'الكمية',
    'unit_price' => 'سعر الوحدة',
    'subtotal' => 'المجموع الفرعي',
    'total' => 'الإجمالي',
    'payment_status' => 'حالة الدفع',
    'paid' => 'مدفوع',
    'unpaid' => 'غير مدفوع',
    'amount_paid' => 'المبلغ المستلم',
    'change_due' => 'الباقي',
    'order_discount' => 'خصم',
    'discount' => 'خصم/تقريب',
    'thank_you' => 'ننتظركم دائما ... <3',
    'scan_instruction' => 'امسح الرمز لعرض القائمة وطلب الطعام',
    'scan_service_instruction' => 'امسح الرمز لطلب :service — بدون الحاجة لفتح القائمة',
    'wifi_heading' => 'واي فاي مجاني',
    'wifi_network' => 'اسم الشبكة',
    'wifi_instruction' => 'امسح للاتصال بشبكة الواي فاي',
    'note' => 'ملاحظة',
    'added_round' => 'إضافة على طلب سابق',
    'order_note' => 'ملاحظة على الطلبية',

    // The printable customer menu (pdf.customer-menu) — the version of
    // the catalog that gets sent to someone who is not in the room.
    'page' => 'صفحة',
    'phone' => 'هاتف',
    'menu_empty' => 'لا توجد أصناف منشورة على القائمة حالياً.',

    // تقرير الصندوق اليومي على الطابعة الحرارية (pdf.day-report-thermal).
    // الصياغة مأخوذة حرفياً من شاشة الصندوق — الشخص اللي بيمسك الورقة هو
    // نفسه اللي بيقرأ الشاشة، وكلمتين مختلفتين لنفس الرقم بتخلّيه يشك بوحدة
    // منهم.
    'day_report' => [
        'title' => 'تقرير الصندوق',
        'business_date' => 'يوم العمل',
        'printed_at' => 'وقت الطباعة',
        'printed_by' => 'طبعه',

        'opening' => 'رصيد بداية اليوم',
        'collected' => 'تحصيل الطلبات',
        'manual_in' => 'تحصيل يدوي',
        'total_in' => 'إجمالي الداخل',
        'expenses' => 'المصروفات النقدية',
        'manual_out' => 'صرف نقدي',
        'total_out' => 'إجمالي الخارج',
        'expected' => 'الرصيد المتوقع',

        'counted' => 'الرصيد المعدود',
        'difference' => 'الفرق',
        'over' => 'زيادة',
        'short' => 'عجز',
        'balanced' => 'مطابق',

        'orders_count' => 'عدد الطلبات',
        'staff_deductions' => 'خصومات الموظفين',
        'bill_discounts' => 'خصومات الفواتير',
        'not_in_drawer' => 'للعلم فقط — لا تُطرح من الصندوق',

        'day_closed' => 'اليوم مغلق',
        'day_open' => 'اليوم ما زال مفتوحاً',
        'closed_by' => 'أغلقه',
        'closing_notes' => 'ملاحظات الإغلاق',

        // تُطبع على تقرير يوم لم يُغلق بعد. الورقة بتضلّ صالحة، بس اللي
        // بيقرأها لازم يعرف إن الأرقام ممكن تتغيّر قبل نهاية الوردية.
        'provisional' => 'أرقام غير نهائية — لم يُغلق اليوم بعد',
    ],
];
