import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Bumped after any successful write (decision, new request, new claim,
/// notification read...). Lists and the dashboard watch it and reload, so
/// every tab reflects the change without manual refresh.
class DataRevision extends Notifier<int> {
  @override
  int build() => 0;

  void bump() => state++;
}

final dataRevisionProvider = NotifierProvider<DataRevision, int>(DataRevision.new);
