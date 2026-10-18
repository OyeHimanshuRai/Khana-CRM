import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

// Server and Accounts client id come from --dart-define so one build can point at any install.
const String baseUrl = String.fromEnvironment('BASE_URL', defaultValue: 'https://khana.factualtechnologies.tech');
const String partnerClientId = String.fromEnvironment('PARTNER_CLIENT_ID');
const String redirectUrl = 'in.restroone.partner://oauth/callback';

class ApiException implements Exception {
  ApiException(this.status, this.message);

  final int status;
  final String message;

  @override
  String toString() => message;
}

// Talks to CRM's /api/v1 and keeps the session in secure storage.
class Api {
  Api._();

  static final Api instance = Api._();

  final _storage = const FlutterSecureStorage();
  String? token;
  List<String> abilities = [];
  String userName = '';
  String shopName = '';

  bool can(String ability) => abilities.contains(ability);

  Future<bool> restore() async {
    token = await _storage.read(key: 'token');
    final saved = await _storage.read(key: 'session');
    if (token == null || saved == null) return false;
    _remember(jsonDecode(saved) as Map<String, dynamic>);
    return true;
  }

  Future<void> exchange(String accountsToken) => _signIn('/auth/exchange', {'access_token': accountsToken});

  Future<void> login(String email, String password) => _signIn('/auth/login', {'email': email, 'password': password});

  Future<void> logout() async {
    try {
      await post('/auth/logout', {});
    } catch (_) {}
    token = null;
    abilities = [];
    await _storage.deleteAll();
  }

  Future<void> _signIn(String path, Map<String, dynamic> body) async {
    final data = await post(path, {...body, 'device_name': 'Partner app'});
    token = data['token'] as String;
    await _storage.write(key: 'token', value: token);
    final session = {
      'abilities': data['abilities'],
      'user': data['user']?['name'] ?? '',
      'shop': data['shop']?['name'] ?? '',
    };
    await _storage.write(key: 'session', value: jsonEncode(session));
    _remember(session);
  }

  void _remember(Map<String, dynamic> session) {
    abilities = List<String>.from(session['abilities'] as List? ?? []);
    userName = session['user'] as String? ?? '';
    shopName = session['shop'] as String? ?? '';
  }

  Future<void> setShopName(String name) async {
    shopName = name;
    await _storage.write(
      key: 'session',
      value: jsonEncode({'abilities': abilities, 'user': userName, 'shop': name}),
    );
  }

  Future<Map<String, dynamic>> get(String path) => _send('GET', path);

  Future<Map<String, dynamic>> post(String path, Map<String, dynamic> body) => _send('POST', path, body);

  Future<Map<String, dynamic>> put(String path, Map<String, dynamic> body) => _send('PUT', path, body);

  Future<Map<String, dynamic>> _send(String method, String path, [Map<String, dynamic>? body]) async {
    final request = http.Request(method, Uri.parse('$baseUrl/api/v1$path'))
      ..headers.addAll({
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
      });
    if (body != null) request.body = jsonEncode(body);

    final http.Response response;
    try {
      response = await http.Response.fromStream(await request.send().timeout(const Duration(seconds: 20)));
    } catch (_) {
      throw ApiException(0, 'Could not reach the server. Check the internet and try again.');
    }

    final data = response.body.isEmpty ? <String, dynamic>{} : jsonDecode(response.body) as Map<String, dynamic>;
    if (response.statusCode >= 400) {
      throw ApiException(response.statusCode, _message(response.statusCode, data));
    }
    return data;
  }

  String _message(int status, Map<String, dynamic> data) {
    if (status == 401) return 'Your sign-in has ended. Please sign in again.';
    if (status == 429) return 'Too many tries. Wait a minute and try again.';
    final errors = data['errors'];
    if (errors is Map && errors.isNotEmpty) return (errors.values.first as List).first.toString();
    return data['message']?.toString() ?? 'Something went wrong ($status).';
  }
}

double toDouble(Object? value) => value is num ? value.toDouble() : double.tryParse('$value') ?? 0;

String money(Object? value) => '₹${toDouble(value).toStringAsFixed(2)}';
