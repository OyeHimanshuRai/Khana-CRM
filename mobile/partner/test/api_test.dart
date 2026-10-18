import 'package:flutter_test/flutter_test.dart';
import 'package:restro_partner/api.dart';

void main() {
  test('money reads numbers and numeric strings', () {
    expect(money(180), '₹180.00');
    expect(money('60.5'), '₹60.50');
    expect(money(null), '₹0.00');
  });

  test('a role sees only what its abilities allow', () {
    Api.instance.abilities = ['tables:read'];
    expect(Api.instance.can('tables:read'), isTrue);
    expect(Api.instance.can('dashboard:read'), isFalse);
  });
}
