<?php

return [
    'status_updated' => 'تم تحديث حالة الطلب.',
    'marked_paid' => 'تم تحديد الطلب كمدفوع.',
    'partially_paid' => 'تم استلام :paid — الباقي :remaining. الطاولة ما زالت مفتوحة.',
    'discount_updated' => 'تم تحديث الخصم.',
    'service_charge_added' => 'تمت إضافة الخدمة إلى الفاتورة.',
    'item_price_updated' => 'تم تعديل سعر الصنف.',
    'item_removed' => 'تمت إزالة الصنف من الطلب.',
    'confirmed' => 'تم تأكيد الطلب وإرساله للمطبخ.',
    'print_queued' => 'تم إرسال الطلب لطابعة المطبخ — إذا كانت الطابعة غير متصلة الآن، سيُعاد إرساله تلقائيًا فور اتصالها.',
    'table_moved' => 'تم نقل الطلب إلى الطاولة الجديدة.',
    'tables_merged' => 'تم دمج الطلب مع طلب الطاولة الوجهة.',
    'merged_into' => 'تم دمجه مع :reference',
    'cannot_move_paid' => 'هذا الطلب مدفوع بالفعل ولا يمكن نقله أو دمجه — قم بتسوية طاولة جديدة بدلاً من ذلك.',
    'cannot_merge_into_paid' => 'طلب الطاولة الوجهة مدفوع بالفعل — لا يمكن إضافة أصناف مدمجة إليه.',
    'move_table_same' => 'هذا الطلب موجود بالفعل على هذه الطاولة.',

    // Printing outcomes. 'print_queued' is the only one of the three that
    // means paper is coming.
    'print_no_printer' => 'لا توجد طابعة مُفعّلة للمحطة: :stations — لن تصل هذه الأصناف إلى المطبخ. راجع إعدادات الطابعات.',
    'print_nothing_to_send' => 'لا يوجد شيء لإرساله لهذا الطلب.',

    // Cancelling a table: clearing it without charging for it.
    'cancel_order' => 'إلغاء الطاولة',
    'cancelled' => 'تم إلغاء الطلب وتفريغ الطاولة.',
    'cancelled_after_printing' => 'تم إلغاء الطلب — لكن تذكرته وصلت المطبخ سابقاً، فأبلغهم بنفسك.',
    'cannot_cancel_paid' => 'هذا الطلب مدفوع بالفعل ولا يمكن إلغاؤه — أي تصحيح بعد الدفع يكون استرجاعاً.',
    'already_cancelled' => 'هذا الطلب ملغى بالفعل.',
    'cannot_edit_closed' => 'لا يمكن تعديل أصناف طلب مغلق — الطلب مكتمل أو ملغى. استخدم الخصم أو ألغِ الطاولة.',
];
