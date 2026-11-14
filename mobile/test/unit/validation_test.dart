import 'package:flutter_test/flutter_test.dart';
import 'package:store_system/features/auth/presentation/change_password_screen.dart';
import 'package:store_system/features/claims/data/claims_repository.dart';
import 'package:store_system/features/requests/domain/qty_validation.dart';

void main() {
  group('validateApprovedQty (approve editor)', () {
    test('accepts 0..ceiling', () {
      expect(validateApprovedQty('0', '20.000'), isNull);
      expect(validateApprovedQty('20', '20.000'), isNull);
      expect(validateApprovedQty('19.999', '20.000'), isNull);
      expect(validateApprovedQty('12.5', '12.500'), isNull);
      expect(validateApprovedQty(' 7 ', '20'), isNull);
    });

    test('rejects above ceiling', () {
      expect(validateApprovedQty('20.001', '20.000'), 'Max 20');
      expect(validateApprovedQty('21', '20.000'), 'Max 20');
      expect(validateApprovedQty('1', null), 'Max 0');
    });

    test('rejects empty, negative, garbage and too many decimals', () {
      expect(validateApprovedQty('', '20'), 'Enter a quantity');
      expect(validateApprovedQty(null, '20'), 'Enter a quantity');
      expect(validateApprovedQty('-1', '20'), 'Cannot be negative');
      expect(validateApprovedQty('abc', '20'), 'Enter a valid number');
      expect(validateApprovedQty('1.', '20'), 'Enter a valid number');
      expect(validateApprovedQty('1.2345', '20'), 'At most 3 decimals');
      expect(validateApprovedQty('1.2340', '20'), isNull); // trailing zero is fine
    });

    test('decimal precision is exact (no float error)', () {
      // 0.1 + 0.2 style traps: compare as decimals, not doubles.
      expect(validateApprovedQty('0.3', '0.300'), isNull);
      expect(validateApprovedQty('9007199254740993', '9007199254740992'), 'Max 9007199254740992');
    });
  });

  group('other validators', () {
    test('requested qty must be > 0', () {
      expect(validateRequestedQty('0'), 'Must be more than 0');
      expect(validateRequestedQty('0.001'), isNull);
      expect(validateRequestedQty(''), 'Enter a quantity');
    });

    test('amount respects currency decimals', () {
      expect(validateAmount('45000', decimals: 0), isNull);
      expect(validateAmount('45,000', decimals: 0), isNull);
      expect(validateAmount('45000.50', decimals: 0), 'Whole numbers only');
      expect(validateAmount('10.25', decimals: 2), isNull);
      expect(validateAmount('0', decimals: 2), 'Must be more than 0');
    });

    test('normalizeDecimal / decimalEquals', () {
      expect(normalizeDecimal('018.50'), '18.5');
      expect(normalizeDecimal('20.000'), '20');
      expect(normalizeDecimal('45,000'), '45000');
      expect(decimalEquals('20.000', '20'), isTrue);
      expect(decimalEquals('0', '0.000'), isTrue);
      expect(decimalEquals('1', 'x'), isFalse);
    });

    test('new password rules', () {
      expect(validateNewPassword('short1', 'old'), 'At least 8 characters');
      expect(validateNewPassword('abcdefgh', 'old'), 'Use both letters and numbers');
      expect(validateNewPassword('12345678', 'old'), 'Use both letters and numbers');
      expect(validateNewPassword('password1', 'password1'), 'Must be different from your current password');
      expect(validateNewPassword('newpass123', 'password1'), isNull);
    });

    test('receipt file rules', () {
      expect(const ReceiptFile(path: 'p', name: 'r.pdf', size: 1000).validate(), isNull);
      expect(const ReceiptFile(path: 'p', name: 'r.JPG', size: 1000).validate(), isNull);
      expect(const ReceiptFile(path: 'p', name: 'r.heic', size: 1000).validate(), contains('PDF, JPG or PNG'));
      expect(const ReceiptFile(path: 'p', name: 'r.png', size: 4 * 1024 * 1024 + 1).validate(), contains('4 MB'));
    });
  });
}
