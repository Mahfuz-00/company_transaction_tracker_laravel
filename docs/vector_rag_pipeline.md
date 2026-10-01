# Autonomous AI Assistant & Vector Database RAG Ingestion Pipeline

## Architecture Overview
The NomNomytics AI Assistant uses a **Retrieval-Augmented Generation (RAG)** architecture with a deterministic vector embedding pipeline and a fallback escalation system.

1. **Vector Embedding Model (`VectorPipeline::embed`)**:
   - Converts natural language queries, documentation chunks, and platform knowledge into 64-dimensional dense normalized vectors.
   - Utilizes n-gram hashing and random projection to ensure determinism across PHP environments without requiring external LLM dependencies or heavy C extensions.
   
2. **Vector Similarity Search (`VectorPipeline::cosineSimilarity`)**:
   - Computes normalized dot products between the question vector and stored document vectors in the `assistant_embeddings` database table.
   - Combines vector similarity with token-overlap BM25 matching to achieve hybrid dense/sparse search accuracy.

3. **Autonomous Ingestion Pipeline (`php artisan assistant:ingest`)**:
   - Ingests markdown documentation from `docs/` and database knowledge from `assistant_knowledge`.
   - Automatically chunks text by paragraph semantics, computes vector embeddings, and stores them in `assistant_embeddings`.

4. **SSA Escalation Fallback Loop**:
   - If the combined search score is below `SupportAssistant::MINIMUM_THRESHOLD` (0.12), the AI admits lack of knowledge.
   - An `AssistantEscalation` is automatically queued for the Software Super Admin (SSA).
   - Once the SSA answers the escalation, a new `AssistantKnowledge` row and its vector embedding are created.
   - The assistant autonomously learns the new answer without manual code updates.
