/**
 * Knowledge Graph / Entity Linking
 *
 * Builds a domain-specific knowledge graph of entities
 * (people, strategies, concepts) and their relationships.
 */

const { getAllArticles, querySimilar } = require('./pinecone');
const { generateEmbedding } = require('./embeddings');
const { getClient } = require('./claude');

// Entity types for real estate domain
const ENTITY_TYPES = {
  STRATEGY: 'strategy',      // BRRRR, house hacking, etc.
  CONCEPT: 'concept',        // Cash flow, equity, etc.
  LOCATION: 'location',      // Cities, provinces
  REGULATION: 'regulation',  // Laws, requirements
  METRIC: 'metric',          // ROI, cap rate, etc.
  PRODUCT: 'product',        // Mortgage types, insurance
  PERSON: 'person'           // Experts, authors
};

// Knowledge graph storage (in production, use a graph database)
const knowledgeGraph = {
  entities: new Map(),
  relationships: []
};

/**
 * Extract entities from content using Claude
 */
async function extractEntities(content, title = '') {
  const client = getClient();

  const prompt = `Extract real estate entities from this content.

Entity types:
- strategy: Investment strategies (BRRRR, house hacking, etc.)
- concept: Financial concepts (cash flow, equity, appreciation)
- location: Canadian cities, provinces, regions
- regulation: Laws, requirements, qualifications
- metric: Financial metrics (ROI, cap rate, cash-on-cash)
- product: Financial products (mortgage types, insurance)
- person: Named experts or authors

CONTENT:
${title}
${content.slice(0, 4000)}

Return JSON:
{
  "entities": [
    {"name": "BRRRR Strategy", "type": "strategy", "context": "how it was mentioned"},
    {"name": "Toronto", "type": "location", "context": "market discussion"}
  ],
  "relationships": [
    {"from": "BRRRR Strategy", "to": "Cash Flow", "type": "enables"}
  ]
}`;

  const response = await client.messages.create({
    model: 'claude-sonnet-4-20250514',
    max_tokens: 800,
    messages: [{ role: 'user', content: prompt }]
  });

  try {
    const text = response.content[0].text;
    const jsonMatch = text.match(/\{[\s\S]*\}/);
    return jsonMatch ? JSON.parse(jsonMatch[0]) : { entities: [], relationships: [] };
  } catch (e) {
    return { entities: [], relationships: [] };
  }
}

/**
 * Add entity to knowledge graph
 */
function addEntity(entity) {
  const key = normalizeEntityName(entity.name);

  if (!knowledgeGraph.entities.has(key)) {
    knowledgeGraph.entities.set(key, {
      name: entity.name,
      normalizedName: key,
      type: entity.type,
      mentions: [],
      relatedArticles: []
    });
  }

  const existing = knowledgeGraph.entities.get(key);

  if (entity.articleId && !existing.relatedArticles.includes(entity.articleId)) {
    existing.relatedArticles.push(entity.articleId);
  }

  if (entity.context) {
    existing.mentions.push({
      context: entity.context,
      articleId: entity.articleId
    });
  }

  return existing;
}

/**
 * Add relationship between entities
 */
function addRelationship(from, to, type) {
  const relationship = {
    from: normalizeEntityName(from),
    to: normalizeEntityName(to),
    type
  };

  // Avoid duplicates
  const exists = knowledgeGraph.relationships.some(r =>
    r.from === relationship.from &&
    r.to === relationship.to &&
    r.type === relationship.type
  );

  if (!exists) {
    knowledgeGraph.relationships.push(relationship);
  }

  return relationship;
}

/**
 * Normalize entity name for consistent lookup
 */
function normalizeEntityName(name) {
  return name.toLowerCase().trim().replace(/\s+/g, '-');
}

/**
 * Get entity by name
 */
function getEntity(name) {
  return knowledgeGraph.entities.get(normalizeEntityName(name));
}

/**
 * Get related entities
 */
function getRelatedEntities(entityName) {
  const normalized = normalizeEntityName(entityName);
  const related = [];

  for (const rel of knowledgeGraph.relationships) {
    if (rel.from === normalized) {
      const entity = knowledgeGraph.entities.get(rel.to);
      if (entity) {
        related.push({ entity, relationship: rel.type, direction: 'outgoing' });
      }
    }
    if (rel.to === normalized) {
      const entity = knowledgeGraph.entities.get(rel.from);
      if (entity) {
        related.push({ entity, relationship: rel.type, direction: 'incoming' });
      }
    }
  }

  return related;
}

/**
 * Build knowledge graph from all articles
 */
async function buildKnowledgeGraph() {
  const articles = await getAllArticles();

  for (const article of articles) {
    const meta = article.metadata || article;

    // Extract entities (using cached if available)
    const entities = meta.entities || [];

    for (const entityName of entities) {
      addEntity({
        name: entityName,
        type: guessEntityType(entityName),
        articleId: meta.postId
      });
    }

    // Add cluster as a concept entity
    if (meta.topicCluster) {
      addEntity({
        name: formatClusterName(meta.topicCluster),
        type: ENTITY_TYPES.CONCEPT,
        articleId: meta.postId
      });
    }
  }

  return getGraphStats();
}

/**
 * Guess entity type from name
 */
function guessEntityType(name) {
  const lower = name.toLowerCase();

  if (lower.includes('strategy') || lower.includes('method') || lower.includes('approach')) {
    return ENTITY_TYPES.STRATEGY;
  }
  if (lower.includes('rate') || lower.includes('roi') || lower.includes('%')) {
    return ENTITY_TYPES.METRIC;
  }
  if (lower.includes('mortgage') || lower.includes('loan') || lower.includes('insurance')) {
    return ENTITY_TYPES.PRODUCT;
  }

  // Canadian locations
  const canadianCities = ['toronto', 'vancouver', 'calgary', 'edmonton', 'ottawa', 'montreal', 'winnipeg'];
  if (canadianCities.some(city => lower.includes(city))) {
    return ENTITY_TYPES.LOCATION;
  }

  return ENTITY_TYPES.CONCEPT;
}

/**
 * Format cluster name
 */
function formatClusterName(cluster) {
  return cluster
    .split('-')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

/**
 * Get knowledge graph statistics
 */
function getGraphStats() {
  const typeCount = {};
  for (const entity of knowledgeGraph.entities.values()) {
    typeCount[entity.type] = (typeCount[entity.type] || 0) + 1;
  }

  return {
    totalEntities: knowledgeGraph.entities.size,
    totalRelationships: knowledgeGraph.relationships.length,
    entitiesByType: typeCount
  };
}

/**
 * Find articles about an entity
 */
function findArticlesAboutEntity(entityName) {
  const entity = getEntity(entityName);
  if (!entity) return [];

  return entity.relatedArticles;
}

/**
 * Suggest entity-based links
 * Links articles that share common entities
 */
async function suggestEntityBasedLinks(postId) {
  const articles = await getAllArticles();
  const targetArticle = articles.find(a =>
    (a.metadata?.postId || a.postId) === postId
  );

  if (!targetArticle) return [];

  const targetEntities = targetArticle.metadata?.entities || [];
  const suggestions = [];

  for (const article of articles) {
    const meta = article.metadata || article;
    if (meta.postId === postId) continue;

    const articleEntities = meta.entities || [];

    // Find shared entities
    const sharedEntities = targetEntities.filter(e =>
      articleEntities.some(ae =>
        normalizeEntityName(ae) === normalizeEntityName(e)
      )
    );

    if (sharedEntities.length > 0) {
      suggestions.push({
        postId: meta.postId,
        title: meta.title,
        url: meta.url,
        sharedEntities,
        entityOverlap: sharedEntities.length
      });
    }
  }

  return suggestions.sort((a, b) => b.entityOverlap - a.entityOverlap);
}

/**
 * Export graph for visualization
 */
function exportGraphForVisualization() {
  const nodes = [];
  const edges = [];

  for (const [key, entity] of knowledgeGraph.entities) {
    nodes.push({
      id: key,
      label: entity.name,
      type: entity.type,
      size: entity.relatedArticles.length
    });
  }

  for (const rel of knowledgeGraph.relationships) {
    edges.push({
      source: rel.from,
      target: rel.to,
      label: rel.type
    });
  }

  return { nodes, edges };
}

module.exports = {
  ENTITY_TYPES,
  extractEntities,
  addEntity,
  addRelationship,
  getEntity,
  getRelatedEntities,
  buildKnowledgeGraph,
  getGraphStats,
  findArticlesAboutEntity,
  suggestEntityBasedLinks,
  exportGraphForVisualization
};
