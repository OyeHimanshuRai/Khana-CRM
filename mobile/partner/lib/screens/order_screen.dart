import 'package:flutter/material.dart';

import '../api.dart';
import '../main.dart';

// The captain picks dishes from the menu and sends them to the kitchen in one go.
class OrderScreen extends StatefulWidget {
  const OrderScreen({super.key, required this.tableId, required this.tableName});

  final int tableId;
  final String tableName;

  @override
  State<OrderScreen> createState() => _OrderScreenState();
}

class _OrderScreenState extends State<OrderScreen> {
  List<Map<String, dynamic>>? _menu;
  final Map<String, int> _cart = {};
  final Map<String, Map<String, dynamic>> _picked = {};
  String _search = '';
  bool _sending = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final data = await Api.instance.get('/menu?per_page=200');
      setState(() => _menu = List<Map<String, dynamic>>.from(data['data'] as List));
    } catch (e) {
      if (!mounted) return;
      setState(() => _menu = []);
      showError(context, e);
    }
  }

  // A cart line is a dish plus its size, so the same dish in two sizes stays two lines.
  String _key(Map<String, dynamic> dish, Map<String, dynamic>? size) => '${dish['id']}:${size?['id'] ?? ''}';

  Future<void> _add(Map<String, dynamic> dish) async {
    final sizes = List<Map<String, dynamic>>.from(dish['variants'] as List? ?? []).where((v) => v['is_available'] != false).toList();
    Map<String, dynamic>? size;
    if (sizes.isNotEmpty) {
      size = await showModalBottomSheet<Map<String, dynamic>>(
        context: context,
        builder: (_) => SafeArea(
          child: ListView(shrinkWrap: true, children: [
            for (final s in sizes)
              ListTile(title: Text('${s['name']}'), trailing: Text(money(s['price'])), onTap: () => Navigator.pop(context, s)),
          ]),
        ),
      );
      if (size == null) return;
    }
    final key = _key(dish, size);
    setState(() {
      _cart[key] = (_cart[key] ?? 0) + 1;
      _picked[key] = {'dish': dish, 'size': size};
    });
  }

  void _change(String key, int by) {
    setState(() {
      final quantity = (_cart[key] ?? 0) + by;
      if (quantity <= 0) {
        _cart.remove(key);
        _picked.remove(key);
      } else {
        _cart[key] = quantity.clamp(1, 30);
      }
    });
  }

  double get _total => _cart.entries.fold(0, (sum, e) {
        final line = _picked[e.key]!;
        final price = line['size']?['price'] ?? line['dish']['dine_in_price'] ?? line['dish']['price'];
        return sum + toDouble(price) * e.value;
      });

  Future<void> _send() async {
    setState(() => _sending = true);
    try {
      final data = await Api.instance.post('/tables/${widget.tableId}/orders', {
        'items': [
          for (final e in _cart.entries)
            {
              'product_id': _picked[e.key]!['dish']['id'],
              'variant_id': _picked[e.key]!['size']?['id'],
              'quantity': e.value,
            },
        ],
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('${data['message']}')));
      Navigator.of(context).pop(true);
    } catch (e) {
      if (!mounted) return;
      setState(() => _sending = false);
      showError(context, e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final menu = _menu;
    final shown = (menu ?? [])
        .where((d) => _search.isEmpty || '${d['name']} ${d['category'] ?? ''}'.toLowerCase().contains(_search))
        .toList();
    final count = _cart.values.fold(0, (a, b) => a + b);

    return Scaffold(
      appBar: AppBar(
        title: Text('Order · Table ${widget.tableName}'),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(60),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
            child: TextField(
              decoration: const InputDecoration(hintText: 'Search dishes', prefixIcon: Icon(Icons.search), border: OutlineInputBorder(), isDense: true),
              onChanged: (v) => setState(() => _search = v.trim().toLowerCase()),
            ),
          ),
        ),
      ),
      body: menu == null
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.only(bottom: 96),
              children: [
                for (final e in _cart.entries)
                  ListTile(
                    tileColor: Theme.of(context).colorScheme.primaryContainer,
                    title: Text('${_picked[e.key]!['dish']['name']}${_picked[e.key]!['size'] != null ? ' (${_picked[e.key]!['size']['name']})' : ''}'),
                    trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                      IconButton(icon: const Icon(Icons.remove_circle_outline), onPressed: () => _change(e.key, -1)),
                      Text('${e.value}'),
                      IconButton(icon: const Icon(Icons.add_circle_outline), onPressed: () => _change(e.key, 1)),
                    ]),
                  ),
                for (final dish in shown)
                  ListTile(
                    enabled: dish['is_available'] != false,
                    title: Text('${dish['name']}'),
                    subtitle: Text(dish['is_available'] == false ? 'Sold out' : '${dish['category'] ?? ''}'),
                    trailing: Text(money(dish['dine_in_price'] ?? dish['price'])),
                    onTap: () => _add(dish),
                  ),
              ],
            ),
      bottomNavigationBar: count == 0
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: FilledButton(
                  onPressed: _sending ? null : _send,
                  child: Text(_sending ? 'Sending...' : 'Send $count to kitchen · ${money(_total)}'),
                ),
              ),
            ),
    );
  }
}
