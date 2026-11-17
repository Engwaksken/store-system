import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/json.dart';
import '../../../core/api/paginated.dart';
import '../../../core/api/providers.dart';
import '../../../core/util/data_revision.dart';
import '../domain/account_models.dart';

/// Admin user management (`/users`). The server applies the same rules as the web Users pages.
class UsersRepository {
  UsersRepository(this._api);
  final ApiClient _api;

  Future<Paginated<Account>> list({int page = 1, String? status, String? role, String? q}) async {
    final json = await _api.get('/users', query: {'page': page, 'status': status, 'role': role, 'q': q});
    return Paginated.fromJson(json, Account.fromJson);
  }

  Future<Account> detail(int id) async {
    final json = await _api.get('/users/$id');
    return Account.fromJson(asMap(json['data']));
  }

  Future<UserFormOptions> options({int? userId}) async =>
      UserFormOptions.fromJson(await _api.get('/users/options', query: {'user_id': userId}));

  Future<SaveAccountResult> create(AccountInput input) async =>
      SaveAccountResult.fromJson(await _api.post('/users', body: input.toJson()));

  Future<SaveAccountResult> update(int id, AccountInput input) async =>
      SaveAccountResult.fromJson(await _api.put('/users/$id', body: input.toJson()));
}

final usersRepositoryProvider = Provider<UsersRepository>((ref) => UsersRepository(ref.watch(apiClientProvider)));

final accountDetailProvider = FutureProvider.autoDispose.family<Account, int>((ref, id) {
  ref.watch(dataRevisionProvider);
  return ref.watch(usersRepositoryProvider).detail(id);
});

/// Form choices; pass the user id when editing so closed projects they are on are offered too.
final userFormOptionsProvider = FutureProvider.autoDispose.family<UserFormOptions, int?>(
  (ref, userId) => ref.watch(usersRepositoryProvider).options(userId: userId),
);
