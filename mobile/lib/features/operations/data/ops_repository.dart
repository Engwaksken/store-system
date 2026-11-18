import 'dart:math';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/json.dart';
import '../../../core/api/paginated.dart';
import '../../../core/api/providers.dart';
import '../../../core/util/data_revision.dart';
import '../../auth/data/auth_controller.dart';
import '../../requests/domain/request_models.dart';
import '../domain/ops_models.dart';

/// One-time key so a repeated tap (or a retry after a timeout) never posts the same document twice.
String newIdempotencyKey() {
  final r = Random.secure();
  return List.generate(24, (_) => r.nextInt(36).toRadixString(36)).join();
}

/// Store operations and purchasing endpoints (docs/API.md, "Store operations").
class OpsRepository {
  OpsRepository(this._api);
  final ApiClient _api;

  Future<StoreOptions> storeOptions() async => StoreOptions.fromJson(await _api.get('/store-ops/options'));

  // ---------------------------------------------------------------- issuing

  Future<Paginated<RequestSummary>> issueQueue({int page = 1}) async =>
      Paginated.fromJson(await _api.get('/issues/queue', query: {'page': page}), RequestSummary.fromJson);

  Future<Paginated<IssueSummary>> issues({int page = 1, String? q, bool unacknowledged = false}) async =>
      Paginated.fromJson(await _api.get('/issues', query: {'page': page, 'q': q, if (unacknowledged) 'unacknowledged': 1}), IssueSummary.fromJson);

  Future<IssueDetail> issue(int id) async => IssueDetail.fromJson(asMap((await _api.get('/issues/$id'))['data']));

  Future<IssueForm> issueForm(int requestId, {int? storeId}) async =>
      IssueForm.fromJson(asMap((await _api.get('/requests/$requestId/issue', query: {'store_id': storeId}))['data']));

  Future<IssueResult> issueRequest(
    int requestId, {
    required int storeId,
    required Map<int, String> qty,
    required String recipientName,
    String? notes,
    required String idempotencyKey,
  }) async {
    final json = await _api.post(
      '/requests/$requestId/issue',
      body: {
        'store_id': storeId,
        'qty': {for (final e in qty.entries) '${e.key}': e.value},
        'recipient_name': recipientName.trim(),
        if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
        'idempotency_key': idempotencyKey,
      },
    );
    return IssueResult(issue: IssueDetail.fromJson(asMap(json['data'])), restock: DocRef.maybe(json['restock'], 'pr_no'), replayed: asBool(json['replayed']));
  }

  /// Sends the shortfall to the Overall Manager without issuing. Returns the requisition, or null with [message].
  Future<(DocRef?, String)> requestRestock(int requestId, {required int storeId}) async {
    final json = await _api.post('/requests/$requestId/restock', body: {'store_id': storeId});
    return (DocRef.maybe(json['data'], 'pr_no'), asString(json['message']));
  }

  Future<IssueDetail> acknowledge(int issueId, {String? recipientName, String? remarks}) async {
    final json = await _api.post(
      '/issues/$issueId/acknowledge',
      body: {
        if (recipientName != null && recipientName.trim().isNotEmpty) 'recipient_name': recipientName.trim(),
        if (remarks != null && remarks.trim().isNotEmpty) 'remarks': remarks.trim(),
      },
    );
    return IssueDetail.fromJson(asMap(json['data']));
  }

  // ---------------------------------------------------------------- receipts & adjustments

  Future<Paginated<ReceiptSummary>> receipts({int page = 1, String? q}) async =>
      Paginated.fromJson(await _api.get('/receipts', query: {'page': page, 'q': q}), ReceiptSummary.fromJson);

  Future<ReceiptDetail> receipt(int id) async => ReceiptDetail.fromJson(asMap((await _api.get('/receipts/$id'))['data']));

  Future<ReceiptDetail> createReceipt({
    required int storeId,
    required String receivedOn,
    required String supplierName,
    String? reference,
    String? notes,
    required List<DraftLine> lines,
    required String idempotencyKey,
  }) async {
    final json = await _api.post(
      '/receipts',
      body: {
        'store_id': storeId,
        'received_on': receivedOn,
        'supplier_name': supplierName.trim(),
        if (reference != null && reference.trim().isNotEmpty) 'reference': reference.trim(),
        if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
        'idempotency_key': idempotencyKey,
        'lines': [
          for (final l in lines) {'item_id': l.item.id, 'qty': l.qty.trim(), 'unit_cost': l.unitCost.trim()},
        ],
      },
    );
    return ReceiptDetail.fromJson(asMap(json['data']));
  }

  /// Returns the new on-hand quantity in that store.
  Future<String> adjust({required int storeId, required int itemId, required String qtyDelta, required String reason}) async {
    final json = await _api.post('/adjustments', body: {'store_id': storeId, 'item_id': itemId, 'qty_delta': qtyDelta.trim(), 'reason': reason.trim()});
    return asString(asMap(json['data'])['on_hand']);
  }

  // ---------------------------------------------------------------- transfers

  Future<Paginated<TransferSummary>> transfers({int page = 1, String? status, String? q}) async =>
      Paginated.fromJson(await _api.get('/transfers', query: {'page': page, 'status': status, 'q': q}), TransferSummary.fromJson);

  Future<TransferDetail> transfer(int id) async => TransferDetail.fromJson(asMap((await _api.get('/transfers/$id'))['data']));

  Future<TransferDetail> dispatchTransfer({
    required int fromStoreId,
    required int toStoreId,
    required List<DraftLine> lines,
    String? notes,
    required String idempotencyKey,
  }) async {
    final json = await _api.post(
      '/transfers',
      body: {
        'from_store_id': fromStoreId,
        'to_store_id': toStoreId,
        if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
        'idempotency_key': idempotencyKey,
        'lines': [
          for (final l in lines) {'item_id': l.item.id, 'qty': l.qty.trim()},
        ],
      },
    );
    return TransferDetail.fromJson(asMap(json['data']));
  }

  Future<TransferDetail> receiveTransfer(int id, {required Map<int, String> received, Map<int, String> reasons = const {}, String? notes}) async {
    final json = await _api.post(
      '/transfers/$id/receive',
      body: {
        'received': {for (final e in received.entries) '${e.key}': e.value},
        'reason': {
          for (final e in reasons.entries)
            if (e.value.trim().isNotEmpty) '${e.key}': e.value.trim(),
        },
        if (notes != null && notes.trim().isNotEmpty) 'receive_notes': notes.trim(),
      },
    );
    return TransferDetail.fromJson(asMap(json['data']));
  }

  Future<TransferDetail> cancelTransfer(int id, String reason) async =>
      TransferDetail.fromJson(asMap((await _api.post('/transfers/$id/cancel', body: {'reason': reason.trim()}))['data']));

  // ---------------------------------------------------------------- returns

  Future<Paginated<ReturnSummary>> returns({int page = 1, String? q}) async =>
      Paginated.fromJson(await _api.get('/returns', query: {'page': page, 'q': q}), ReturnSummary.fromJson);

  Future<ReturnDetail> stockReturn(int id) async => ReturnDetail.fromJson(asMap((await _api.get('/returns/$id'))['data']));

  Future<ReturnDetail> recordReturn(
    int issueId, {
    required int storeId,
    required Map<int, String> qty,
    required String returnedByName,
    required String reason,
    required String idempotencyKey,
  }) async {
    final json = await _api.post(
      '/issues/$issueId/return',
      body: {
        'store_id': storeId,
        'qty': {for (final e in qty.entries) '${e.key}': e.value},
        'returned_by_name': returnedByName.trim(),
        'reason': reason.trim(),
        'idempotency_key': idempotencyKey,
      },
    );
    return ReturnDetail.fromJson(asMap(json['data']));
  }

  // ---------------------------------------------------------------- purchasing

  Future<Paginated<PrSummary>> requisitions({int page = 1, String? status, String? q, bool restockOnly = false}) async => Paginated.fromJson(
    await _api.get('/purchase-requisitions', query: {'page': page, 'status': status, 'q': q, if (restockOnly) 'restock': 1}),
    PrSummary.fromJson,
  );

  Future<PrDetail> requisition(int id) async => PrDetail.fromJson(asMap((await _api.get('/purchase-requisitions/$id'))['data']));

  Future<PrDetail> submitRequisition(int id) async => PrDetail.fromJson(asMap((await _api.post('/purchase-requisitions/$id/submit'))['data']));

  Future<PrDetail> decideRequisition(int id, {required bool approve, String? reason}) async {
    final json = await _api.post(
      '/purchase-requisitions/$id/decision',
      body: {'decision': approve ? 'approved' : 'rejected', if (reason != null && reason.trim().isNotEmpty) 'reason': reason.trim()},
    );
    return PrDetail.fromJson(asMap(json['data']));
  }

  Future<Paginated<PoSummary>> orders({int page = 1, String? status, String? q}) async =>
      Paginated.fromJson(await _api.get('/purchase-orders', query: {'page': page, 'status': status, 'q': q}), PoSummary.fromJson);

  Future<PoDetail> order(int id) async => PoDetail.fromJson(asMap((await _api.get('/purchase-orders/$id'))['data']));

  Future<PoDetail> decideOrder(int id, {required bool approve, String? reason, required int lockVersion}) async {
    final json = await _api.post(
      '/purchase-orders/$id/decision',
      body: {
        'decision': approve ? 'approved' : 'rejected',
        if (reason != null && reason.trim().isNotEmpty) 'reason': reason.trim(),
        'lock_version': lockVersion,
      },
    );
    return PoDetail.fromJson(asMap(json['data']));
  }
}

final opsRepositoryProvider = Provider<OpsRepository>((ref) => OpsRepository(ref.watch(apiClientProvider)));

final storeOptionsProvider = FutureProvider.autoDispose<StoreOptions>((ref) {
  ref.watch(currentUserProvider.select((u) => u?.id));
  return ref.watch(opsRepositoryProvider).storeOptions();
});

final issueDetailProvider = FutureProvider.autoDispose.family<IssueDetail, int>((ref, id) {
  ref.watch(dataRevisionProvider);
  return ref.watch(opsRepositoryProvider).issue(id);
});

final receiptDetailProvider = FutureProvider.autoDispose.family<ReceiptDetail, int>((ref, id) => ref.watch(opsRepositoryProvider).receipt(id));

final transferDetailProvider = FutureProvider.autoDispose.family<TransferDetail, int>((ref, id) {
  ref.watch(dataRevisionProvider);
  return ref.watch(opsRepositoryProvider).transfer(id);
});

final returnDetailProvider = FutureProvider.autoDispose.family<ReturnDetail, int>((ref, id) => ref.watch(opsRepositoryProvider).stockReturn(id));

final prDetailProvider = FutureProvider.autoDispose.family<PrDetail, int>((ref, id) {
  ref.watch(dataRevisionProvider);
  return ref.watch(opsRepositoryProvider).requisition(id);
});

final poDetailProvider = FutureProvider.autoDispose.family<PoDetail, int>((ref, id) {
  ref.watch(dataRevisionProvider);
  return ref.watch(opsRepositoryProvider).order(id);
});
