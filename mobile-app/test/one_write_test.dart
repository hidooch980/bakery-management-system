import 'package:bakery_app/utils/one_write.dart';
import 'package:flutter_test/flutter_test.dart';

/// نامی که به قصد گره خورده، نه به فراخوانی.
///
/// این کوچک است ولی دو خطای مخالف را از هم جدا می‌کند، و هر دو پول واقعی
/// مغازه‌اند:
///
///   - نامی که هر بار تازه ساخته شود، یک جوابِ گم‌شده را به دو پرداخت
///     تبدیل می‌کند.
///   - نامی که زیادی بماند، پرداخت **بعدی** را تکرارِ قبلی نشان می‌دهد و
///     اصلاً ثبتش نمی‌کند — پولی که داده شده و هیچ ردیفی ندارد، که از
///     دوباره‌نویسی هم بدتر است.
void main() {
  test('the same intent keeps the same name', () {
    final writes = OneWrite();

    expect(
      writes.nameFor('supplier-7-1000000'),
      writes.nameFor('supplier-7-1000000'),
    );
  });

  test('a different intent gets a different name', () {
    final writes = OneWrite();

    expect(
      writes.nameFor('supplier-7-1000000'),
      isNot(writes.nameFor('supplier-7-2000000')),
    );
  });

  test('the same amount to a different mill is a different name', () {
    final writes = OneWrite();

    expect(
      writes.nameFor('supplier-7-1000000'),
      isNot(writes.nameFor('supplier-9-1000000')),
    );
  });

  test('a name is forgotten once the write has taken', () {
    // کار بعدی، کار بعدی است. اگر نام بماند، پرداخت دومِ همان مبلغ به همان
    // کارخانه — که در ماه پیش می‌آید — تکرار دیده می‌شود و گم می‌شود.
    final writes = OneWrite();

    final first = writes.nameFor('supplier-7-1000000');
    writes.done('supplier-7-1000000');
    final second = writes.nameFor('supplier-7-1000000');

    expect(second, isNot(first));
  });

  test('forgetting one intent leaves the others alone', () {
    final writes = OneWrite();

    final kept = writes.nameFor('customer-3');
    writes.nameFor('supplier-7-1000000');
    writes.done('supplier-7-1000000');

    expect(writes.nameFor('customer-3'), kept);
    expect(writes.open, 1);
  });

  test('nothing is remembered before anything is asked', () {
    expect(OneWrite().open, 0);
  });
}
