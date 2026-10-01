# RAG & Vector Database Architecture Documentation

## Executive Overview
NomNomytics incorporates an end-to-end Retrieval-Augmented Generation (RAG) and Vector Database pipeline tailored specifically for multi-institution meal accounting, autonomous technical support, and financial forecasting.

---

## 1. Vector Database Schema & Architecture

### Database Table: `forecast_embeddings`
Stores deterministic dense vector embeddings computed from institutional historical consumption and macroeconomic benchmark datasets.

| Column | Type | Description |
|---|---|---|
| `id` | BIGINT UNSIGNED | Primary key identifier |
| `institution_id` | BIGINT UNSIGNED NULL | Tenant scoping (NULL for global benchmarks) |
| `period_month` | DATE | Temporal baseline for time-series aggregation |
| `vector` | JSON / LONGTEXT | Dense normalized floating-point embedding array (64-dim) |
| `metadata` | JSON | Feature payload: roster count, net expenses, meal volume, subsidies |
| `created_at` / `updated_at` | TIMESTAMP | Audit trail timestamps |

---

## 2. Deterministic Embedding Generation Logic (`VectorPipeline::embed`)

To guarantee environment reproducibility without third-party external LLM API rate limits or latency dependencies:
1. **Tokenization & Normalization**: Text and numerical feature maps are parsed into normalized token streams.
2. **N-Gram Hashing**: Feature streams are mapped across sub-word and sliding-window n-grams (unigrams, bigrams, trigrams).
3. **Random Hyperplane Projection**: Token hashes are projected onto a 64-dimensional space using seed-locked random projections.
4. **L2 Unit Normalization**: The resulting vector is normalized such that $\|\vec{v}\|_2 = 1.0$.

---

## 3. Vector Similarity Retrieval (`cosineSimilarity`)

Given query vector $\vec{q}$ and candidate vector $\vec{d}$:
$$\text{Cosine Similarity}(\vec{q}, \vec{d}) = \frac{\vec{q} \cdot \vec{d}}{\|\vec{q}\|_2 \|\vec{d}\|_2} = \sum_{i=1}^{n} q_i \cdot d_i$$

Hybrid scoring merges cosine similarity with BM25 token frequencies to ensure exact technical terms and conceptual context match seamlessly.

---

## 4. Meal Price Governance

In accordance with strict ledger transparency principles, daily similarity retrieval is **never** used to guess meal prices.
Meal pricing is strictly governed by the canonical accounting formula:

$$\text{Cost Per Meal} = \frac{\text{Total Expenses} - \text{Total Subsidies}}{\text{Total Consumed Meals}}$$

- **Total Expenses**: Sum of all recorded, unreversed `meal_expenses` across the calculation period.
- **Total Subsidies**: Active subsidies apportioned to the institution for the period.
- **Total Consumed Meals**: Sum of breakfast, lunch, and dinner tallies from `meal_entries`.

---

## 5. Automated Scheduled Jobs

### Monthly Model Training Job (`forecast:train-monthly`)
- **Execution Schedule**: Last day of every month at 23:59 via Laravel Console Kernel scheduler.
- **Action**: Aggregates the ledger's transactions, validates accounting balances, and fits time-series weight parameters into `forecast_models`.

### 3-Month Rolling Projections (`--rolling`)
- **Execution Schedule**: 1st day of each new calendar month.
- **Action**: Calculates projected meal counts and expense requirements for the next 3 months ($M+1, M+2, M+3$).
- **Benchmark Fallback**: If an institution has fewer than 3 months of verified historical ledger records, the forecaster automatically falls back to centralized country benchmarks stored in `forecast_benchmarks`.
