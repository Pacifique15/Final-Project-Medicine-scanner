#include <ArduinoJson.h>
#include <HTTPClient.h>
#include <LiquidCrystal_I2C.h>
#include <WebServer.h>
#include <WiFi.h>
#include <Wire.h>

const char *WIFI_SSID = "YOUR_WIFI_NAME";
const char *WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";
const char *API_URL = "http://192.168.1.20:8000/api.php?action=verify";
const char *DEVICE_API_KEY = "";

constexpr uint8_t GREEN_LED_PIN = 25;
constexpr uint8_t RED_LED_PIN = 26;
constexpr uint8_t BUZZER_PIN = 27;
constexpr uint8_t LCD_ADDRESS = 0x27;
constexpr uint32_t ALERT_INTERVAL_MS = 250;
constexpr uint32_t WIFI_RETRY_MS = 10000;

LiquidCrystal_I2C lcd(LCD_ADDRESS, 16, 2);
WebServer server(80);

struct AlertSequence {
  bool active = false;
  bool phaseOn = false;
  bool buzzer = false;
  uint8_t ledPin = 0;
  uint8_t pulseCount = 0;
  uint8_t pulsesDone = 0;
  uint32_t nextChange = 0;
};

AlertSequence alert;
uint32_t lastWifiRetry = 0;
bool showedReady = false;
String lastScanStatus = "IDLE";
String lastScanCode = "";
bool lastCommandAcknowledged = false;

void allAlertsOff() {
  digitalWrite(GREEN_LED_PIN, LOW);
  digitalWrite(RED_LED_PIN, LOW);
  digitalWrite(BUZZER_PIN, LOW);
  alert.active = false;
}

void beginAlert(uint8_t ledPin, bool useBuzzer, uint8_t pulses) {
  allAlertsOff();
  alert.active = true;
  alert.phaseOn = true;
  alert.buzzer = useBuzzer;
  alert.ledPin = ledPin;
  alert.pulseCount = pulses;
  alert.pulsesDone = 0;
  alert.nextChange = millis() + ALERT_INTERVAL_MS;
  digitalWrite(ledPin, HIGH);
  if (useBuzzer) digitalWrite(BUZZER_PIN, HIGH);
}

void updateAlert() {
  if (!alert.active || (int32_t)(millis() - alert.nextChange) < 0) return;

  if (alert.phaseOn) {
    digitalWrite(alert.ledPin, LOW);
    if (alert.buzzer) digitalWrite(BUZZER_PIN, LOW);
    alert.pulsesDone++;
    if (alert.pulsesDone >= alert.pulseCount) {
      alert.active = false;
      return;
    }
    alert.phaseOn = false;
  } else {
    digitalWrite(alert.ledPin, HIGH);
    if (alert.buzzer) digitalWrite(BUZZER_PIN, HIGH);
    alert.phaseOn = true;
  }
  alert.nextChange = millis() + ALERT_INTERVAL_MS;
}

void verifiedAlert() {
  beginAlert(GREEN_LED_PIN, false, 3);
}

void expiredAlert() {
  beginAlert(RED_LED_PIN, true, 3);
}

void notFoundAlert() {
  beginAlert(RED_LED_PIN, true, 2);
}

void addCorsHeaders() {
  server.sendHeader("Access-Control-Allow-Origin", "*");
  server.sendHeader("Access-Control-Allow-Methods", "GET, OPTIONS");
  server.sendHeader("Access-Control-Allow-Headers", "Content-Type");
  server.sendHeader("Cache-Control", "no-store");
}

bool validCode(const String &code) {
  if (code.length() < 1 || code.length() > 24) return false;
  for (size_t i = 0; i < code.length(); i++) {
    const char c = code[i];
    if (!isAlphaNumeric(c) && c != '_' && c != '-') return false;
  }
  return true;
}

String encodeQueryValue(const String &value) {
  String encoded;
  char hex[4];
  for (size_t i = 0; i < value.length(); i++) {
    const uint8_t c = static_cast<uint8_t>(value[i]);
    if (isAlphaNumeric(c) || c == '_' || c == '-') {
      encoded += static_cast<char>(c);
    } else {
      snprintf(hex, sizeof(hex), "%%%02X", c);
      encoded += hex;
    }
  }
  return encoded;
}

void showResult(const char *line1, const char *line2) {
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print(line1);
  lcd.setCursor(0, 1);
  lcd.print(line2);
}

void sendJson(int statusCode, const String &payload) {
  addCorsHeaders();
  server.send(statusCode, "application/json", payload);
}

void handleScan() {
  allAlertsOff();
  lastCommandAcknowledged = false;
  const String code = server.arg("code");
  if (!validCode(code)) {
    showResult("INVALID QR CODE", "Scan again");
    sendJson(400, "{\"ok\":false,\"error\":\"Invalid medicine code.\"}");
    return;
  }

  if (WiFi.status() != WL_CONNECTED) {
    showResult("WIFI OFFLINE", "Check network");
    sendJson(503, "{\"ok\":false,\"error\":\"ESP32 is not connected to Wi-Fi.\"}");
    return;
  }

  showResult("Checking medicine", code.c_str());
  HTTPClient http;
  http.setTimeout(6000);
  const String url = String(API_URL) + "&code=" + encodeQueryValue(code);
  if (!http.begin(url)) {
    showResult("API ERROR", "Check API URL");
    sendJson(502, "{\"ok\":false,\"error\":\"Could not start the PHP API request.\"}");
    return;
  }

  http.addHeader("X-Device-Key", DEVICE_API_KEY);
  const int httpCode = http.GET();
  const String body = http.getString();
  http.end();
  if (httpCode < 200 || httpCode >= 300) {
    showResult("API ERROR", "Check PHP server");
    sendJson(502, "{\"ok\":false,\"error\":\"PHP verification request failed.\"}");
    return;
  }

  DynamicJsonDocument result(1024);
  const DeserializationError parseError = deserializeJson(result, body);
  if (parseError) {
    showResult("API ERROR", "Invalid response");
    sendJson(502, "{\"ok\":false,\"error\":\"PHP returned an invalid verification response.\"}");
    return;
  }
  if (!result["ok"].as<bool>()) {
    showResult("API ERROR", "Check PHP server");
    sendJson(502, body);
    return;
  }

  const char *status = result["status"] | "";
  if (strcmp(status, "GENUINE") == 0) {
    showResult("DRUG VERIFIED", "GENUINE");
    verifiedAlert();
  } else if (strcmp(status, "EXPIRED") == 0) {
    showResult("DRUG EXPIRED", "DO NOT USE");
    expiredAlert();
  } else if (strcmp(status, "NOT_FOUND") == 0) {
    showResult("DRUG NOT FOUND", "NOT VERIFIED");
    notFoundAlert();
  } else {
    showResult("API ERROR", "Unknown status");
    sendJson(502, "{\"ok\":false,\"error\":\"PHP returned an unknown medicine status.\"}");
    return;
  }

  lastScanStatus = status;
  lastScanCode = code;
  lastCommandAcknowledged = true;
  JsonObject hardware = result.createNestedObject("hardware");
  hardware["deviceOnline"] = WiFi.status() == WL_CONNECTED;
  hardware["commandAcknowledged"] = lastCommandAcknowledged;
  hardware["greenLedOn"] = digitalRead(GREEN_LED_PIN) == HIGH;
  hardware["redLedOn"] = digitalRead(RED_LED_PIN) == HIGH;
  hardware["buzzerOn"] = digitalRead(BUZZER_PIN) == HIGH;
  hardware["alertActive"] = alert.active;
  hardware["pulseCount"] = alert.pulseCount;
  hardware["pulsesDone"] = alert.pulsesDone;
  hardware["lastScanStatus"] = lastScanStatus;
  hardware["lastScanCode"] = lastScanCode;
  hardware["outputFeedback"] = "ESP32 GPIO output state; no electrical feedback sensor";

  String response;
  serializeJson(result, response);
  sendJson(200, response);
}

void handleStatus() {
  StaticJsonDocument<512> status;
  status["ok"] = true;
  status["deviceOnline"] = WiFi.status() == WL_CONNECTED;
  status["commandAcknowledged"] = lastCommandAcknowledged;
  status["greenLedOn"] = digitalRead(GREEN_LED_PIN) == HIGH;
  status["redLedOn"] = digitalRead(RED_LED_PIN) == HIGH;
  status["buzzerOn"] = digitalRead(BUZZER_PIN) == HIGH;
  status["alertActive"] = alert.active;
  status["pulseCount"] = alert.pulseCount;
  status["pulsesDone"] = alert.pulsesDone;
  status["lastScanStatus"] = lastScanStatus;
  status["lastScanCode"] = lastScanCode;
  status["outputFeedback"] = "ESP32 GPIO output state; no electrical feedback sensor";
  String response;
  serializeJson(status, response);
  sendJson(200, response);
}

void handleOptions() {
  addCorsHeaders();
  server.send(204);
}

void handleNotFound() {
  sendJson(404, "{\"ok\":false,\"error\":\"Endpoint not found.\"}");
}

void setup() {
  Serial.begin(115200);
  pinMode(GREEN_LED_PIN, OUTPUT);
  pinMode(RED_LED_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);
  allAlertsOff();

  Wire.begin();
  lcd.init();
  lcd.backlight();
  showResult("Medicine Scanner", "Connecting WiFi");

  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  server.on("/scan", HTTP_GET, handleScan);
  server.on("/scan", HTTP_OPTIONS, handleOptions);
  server.on("/status", HTTP_GET, handleStatus);
  server.on("/status", HTTP_OPTIONS, handleOptions);
  server.onNotFound(handleNotFound);
  server.begin();
  Serial.println("Medicine Scanner HTTP server started.");
}

void loop() {
  server.handleClient();
  updateAlert();

  if (WiFi.status() == WL_CONNECTED) {
    if (!showedReady) {
      showedReady = true;
      Serial.print("ESP32 IP: ");
      Serial.println(WiFi.localIP());
      showResult("ESP32 READY", WiFi.localIP().toString().c_str());
    }
  } else {
    showedReady = false;
    if (millis() - lastWifiRetry >= WIFI_RETRY_MS) {
      lastWifiRetry = millis();
      WiFi.reconnect();
    }
  }
}
