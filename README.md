# Wardriving Device Performance & Benchmark Tool – Presentation Speech
<img width="952" height="1705" alt="image" src="https://github.com/user-attachments/assets/b3d8ccf0-0675-41ad-a5e6-a80b3ef4be63" />

## 1. Introduction: Why Measure What We Measure?
Hello everyone! When we discuss hardware performance in the wardriving community, we often hear statements like: *"My board scans super fast"*, *"It logs down in dual-band/mixed mode"*, or *"The firmware crashes under heavy load."*

But how do you fairly and objectively benchmark a setup, whether under real-world street conditions or in a wild burst stress test with one ore more spammer units? That is precisely why I created the [**Wardriving Device Performance & Benchmark Tool**](https://chrisdacrunk.de/benchmark) , built alongside my multi-threaded wardriving firmware [ChrisDaDriver](https://github.com/ChrisDaCrunk/ChrisDaDriver).

---

## 2. Two Test Scenarios for True Comparability
* **Real-life test:** Upload any standard wardriving CSV log to evaluate how your hardware performs under normal operational conditions.
* **Stress-Test test:** Run a Wi-Fi and BLE spammer simultaneously, launch your wardriver, and let it log for roughly **2 minutes** under maximum traffic load. Even if your hardware experiences a watchdog reset or crashes mid-test, simply upload the partial or crash log—it provides an honest, valuable metric of device stability and burst limit.

---

## 3. Privacy by Design: Zero Data Retention
Data privacy is a major consideration in wireless logging. Therefore:
* Uploaded CSV logs are processed **strictly in-memory (RAM)**.
* Raw capture data is **never saved to disk or stored on the server**.
* The leaderboard database only records system metadata (`board`, `device`, `firmware`), computed performance metrics (`score`, `peak_burst`, `mix_latency_ms`, `avg_rate`, counters), and the file size (`filebyte`) for duplicate detection.

---

## 4. Logic & Architecture Overview (`BenchMark.html`, `process_and_submit.php`, `get_leaderboard.php`)

### System Architecture & Data Flow

```
[ User Browser ]
       │
       ▼  (1) Upload CSV (RAM Processing Only)
┌────────────────────────────────────────────────────────┐
│ process_and_submit.php                                 │
│  ├── 1. Header & Metadata Parsing                      │
│  ├── 2. Anti-Second-Collision Algorithm                │
│  ├── 3. Sliding Window Latency & Burst Calculations    │
│  └── 4. Performance Score Calculation                  │
└──────────────────────────┬─────────────────────────────┘
                           │
                           ▼  (2) Insert Computed Score (If Unique)
                 ┌───────────────────┐
                 │ MySQL Database    │
                 └─────────┬─────────┘
                           │
                           ▼  (3) Fetch Top 100 Leaderboard
┌────────────────────────────────────────────────────────┐
│ get_leaderboard.php                                    │
│  └── Returns JSON -> Rendered in BenchMark.html        │
└────────────────────────────────────────────────────────┘
```

#### 1. Client Layer (`BenchMark.html`)
* Drag-and-Drop / File selection for `.csv` and `.txt` files (featuring a privacy banner and test instructions).
* Transmits the file via `FormData` (`POST`) to `process_and_submit.php`.
* Renders header metadata, session statistics, benchmark latency cards, and tier ratings:
  * **Elite Benchmark Tier:** > 50,000 pts
  * **High-Performance Tier:** > 30,000 pts
  * **Optimized Tier:** > 15,000 pts
  * **Baseline Tier:** > 5,000 pts
  * **Entry / Low Activity:** < 5,000 pts
* Calls `get_leaderboard.php` to render the Top 100 entries (`ORDER BY score DESC` where `showInBoard = 1`) in a purple/magenta global leaderboard.

#### 2. Server Processing & Metric Engines (`process_and_submit.php`)
* **Header & Column Parsing:** Reads line 1 (extracting WiGLE version and key-value descriptors like `model`, `board`, `app`, `apprelease`/`release`), then identifies column indexes for timestamp (`YYYY-MM-DD HH:MM:SS(.mmm)`) and signal type (`BLE`/`BT` vs. `WiFi`).
* **Anti-Second-Collision Algorithm (Synthetic Linear Distribution):** When logs lack millisecond resolution (where multiple entries share identical `.000ms` timestamps in a single second), the algorithm evenly spreads entries across a 900 ms window within that second to prevent artificial timestamp clustering.
* **Sorting & Baseline Metrics:** Sorts overall, Wi-Fi, and BLE record sets chronologically. Calculates total duration (`totalDurationSec`) and average rate (`avgOps` = total records / seconds).
* **Benchmark Windows:**
  * `computeFastestWindow($recs, 50)`: Identifies the fastest sliding window across **50** consecutive requests, calculating average per-step latency in milliseconds ($\text{duration} / 49$) for Wi-Fi, BLE, and Mixed captures.
  * `findPeakWindow($recs, 3000)`: Uses a sliding window to measure the maximum packet count captured within a **3,000 ms burst window** (`peakBurst`).
* **Performance Score Formula:** Executed when both `benchMix` and `peakBurst` are valid:
  $$\text{burstScore} = \left(\frac{\text{peakBurst}}{3}\right) \times 10$$
  $$\text{speedScore} = \left(\frac{100.0}{\text{benchMix}}\right) \times 5000$$
  $$\text{throughputScore} = \text{avgOps} \times 20$$
  $$\text{Total Score} = \text{round}(\text{burstScore} + \text{speedScore} + \text{throughputScore})$$
* **Deduplication & Storage:** Checks `SELECT COUNT(*) FROM wardrive_leaderboard WHERE filebyte = ?`. If unique, inserts the record into MySQL with `showInBoard = TRUE`.

#### 3. Leaderboard API (`get_leaderboard.php`)
* Connects via `PDO` and returns JSON formatted rankings (`board`, `device`, `firmware`, `score`, `peak_burst`, `mix_latency_ms`, `submitted_at`) for active entries (`showInBoard = 1`), ordered by `score DESC` (Limit 100).

---

## 5. Summary & Conclusion
This tool provides a transparent, repeatable, and objective benchmark framework for wardriving hardware performance—from compact microcontrollers to dedicated multi-threaded platforms like **ChrisDaDriver**. Test your hardware setups, optimize your firmware, and claim your spot on the global leaderboard!
