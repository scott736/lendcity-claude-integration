/**
 * Hybrid Scoring System
 * Combines vector similarity with business rules from WordPress plugin
 *
 * Original WordPress scoring: 300+ points possible
 * New hybrid: Vector score (0-100) + Business rules (0-200) = 0-300
 */

/**
 * Topic cluster definitions and relationships
 */
const CLUSTER_RELATIONSHIPS = {
  'brrrr-strategy': ['financing', 'rental-properties', 'renovation', 'refinancing'],
  'financing': ['mortgages', 'refinancing', 'credit', 'brrrr-strategy'],
  'rental-properties': ['property-management', 'cash-flow', 'brrrr-strategy', 'tenant-screening'],
  'first-time-buyer': ['mortgages', 'home-buying-process', 'financing'],
  'investment-strategy': ['brrrr-strategy', 'rental-properties', 'market-analysis'],
  'mortgages': ['financing', 'refinancing', 'first-time-buyer', 'rates'],
  'market-analysis': ['investment-strategy', 'location', 'timing'],
  'property-management': ['rental-properties', 'tenant-screening', 'maintenance'],
  'tax-strategies': ['incorporation', 'deductions', 'investment-strategy'],
  'renovation': ['brrrr-strategy', 'value-add', 'contractors']
};

/**
 * Funnel stage flow - optimal progression
 */
const FUNNEL_FLOW = {
  'awareness': ['awareness', 'consideration'],      // Can link to same or next
  'consideration': ['consideration', 'decision'],   // Can link to same or next
  'decision': ['decision', 'consideration']         // Can link back for more info
};

/**
 * Persona compatibility matrix
 */
const PERSONA_COMPATIBILITY = {
  'investor': ['investor', 'general'],
  'homebuyer': ['homebuyer', 'first-time-buyer', 'general'],
  'first-time-buyer': ['first-time-buyer', 'homebuyer', 'general'],
  'general': ['general', 'investor', 'homebuyer', 'first-time-buyer']
};

/**
 * Calculate hybrid score for a candidate article
 *
 * @param {Object} source - Source article metadata
 * @param {Object} candidate - Candidate article with vector similarity score
 * @param {Object} options - Scoring options
 * @returns {Object} Detailed score breakdown
 */
function calculateHybridScore(source, candidate, options = {}) {
  const {
    vectorWeight = 0.4,      // 40% vector similarity
    businessWeight = 0.6     // 60% business rules
  } = options;

  // Vector similarity score (0-100)
  // Pinecone returns 0-1, multiply by 100
  const vectorScore = (candidate.score || 0) * 100;

  // Business rule scores
  const clusterScore = calculateClusterScore(source, candidate);
  const funnelScore = calculateFunnelScore(source, candidate);
  const personaScore = calculatePersonaScore(source, candidate);
  const qualityScore = calculateQualityScore(candidate);
  const freshnessScore = calculateFreshnessScore(candidate);
  const linkBalanceScore = calculateLinkBalanceScore(candidate);
  const clickDepthScore = calculateClickDepthScore(candidate);
  const pillarBonus = candidate.isPillar ? 15 : 0;

  // Total business rules score (0-225)
  const businessRulesScore =
    clusterScore +      // 0-50
    funnelScore +       // 0-25
    personaScore +      // -30 to +30
    qualityScore +      // 0-30
    freshnessScore +    // 0-20
    linkBalanceScore +  // 0-25
    clickDepthScore +   // 0-25 (boost deep/orphan pages)
    pillarBonus;        // 0-15

  // Normalize business score to 0-100
  const normalizedBusinessScore = Math.max(0, Math.min(100, (businessRulesScore / 225) * 100));

  // Combined weighted score
  const totalScore = (vectorScore * vectorWeight) + (normalizedBusinessScore * businessWeight);

  return {
    totalScore: Math.round(totalScore * 100) / 100,
    vectorScore: Math.round(vectorScore * 100) / 100,
    businessScore: Math.round(normalizedBusinessScore * 100) / 100,
    breakdown: {
      cluster: clusterScore,
      funnel: funnelScore,
      persona: personaScore,
      quality: qualityScore,
      freshness: freshnessScore,
      linkBalance: linkBalanceScore,
      clickDepth: clickDepthScore,
      pillarBonus
    },
    candidate: {
      postId: candidate.postId,
      title: candidate.title,
      url: candidate.url,
      topicCluster: candidate.topicCluster,
      funnelStage: candidate.funnelStage
    }
  };
}

/**
 * Cluster relevance score (0-50)
 */
function calculateClusterScore(source, candidate) {
  const sourceCluster = source.topicCluster;
  const candidateCluster = candidate.topicCluster;
  const sourceRelated = source.relatedClusters || [];
  const candidateRelated = candidate.relatedClusters || [];

  // Same cluster = max points
  if (sourceCluster === candidateCluster) {
    return 50;
  }

  // Candidate is in source's related clusters
  if (sourceRelated.includes(candidateCluster)) {
    return 40;
  }

  // Check cluster relationship map
  const clusterRelations = CLUSTER_RELATIONSHIPS[sourceCluster] || [];
  if (clusterRelations.includes(candidateCluster)) {
    return 35;
  }

  // Source is in candidate's related clusters
  if (candidateRelated.includes(sourceCluster)) {
    return 30;
  }

  // Check for shared related clusters
  const sharedRelated = sourceRelated.filter(c => candidateRelated.includes(c));
  if (sharedRelated.length > 0) {
    return 20 + Math.min(sharedRelated.length * 3, 10);
  }

  // No relationship found
  return 5;
}

/**
 * Funnel stage flow score (0-25)
 */
function calculateFunnelScore(source, candidate) {
  const sourceStage = source.funnelStage;
  const candidateStage = candidate.funnelStage;

  if (!sourceStage || !candidateStage) return 10;

  const allowedStages = FUNNEL_FLOW[sourceStage] || [];

  // Optimal flow
  if (allowedStages[1] === candidateStage) {
    return 25; // Links to next stage in funnel
  }

  // Same stage
  if (allowedStages[0] === candidateStage) {
    return 20;
  }

  // Reverse flow (going back)
  if (sourceStage === 'decision' && candidateStage === 'consideration') {
    return 15; // Acceptable for more info
  }

  // Skipping stages
  if (sourceStage === 'awareness' && candidateStage === 'decision') {
    return 5; // Not ideal but allowed
  }

  return 10; // Default
}

/**
 * Persona compatibility score (-30 to +30)
 */
function calculatePersonaScore(source, candidate) {
  const sourcePersona = source.targetPersona;
  const candidatePersona = candidate.targetPersona;

  if (!sourcePersona || !candidatePersona) return 0;

  // Same persona = bonus
  if (sourcePersona === candidatePersona) {
    return 30;
  }

  // Compatible personas
  const compatible = PERSONA_COMPATIBILITY[sourcePersona] || [];
  if (compatible.includes(candidatePersona)) {
    return 15;
  }

  // Incompatible (investor reading homebuyer content)
  if (sourcePersona === 'investor' && candidatePersona === 'first-time-buyer') {
    return -20;
  }

  if (sourcePersona === 'first-time-buyer' && candidatePersona === 'investor') {
    return -10; // Aspirational, not as bad
  }

  return 0;
}

/**
 * Content quality score (0-30)
 */
function calculateQualityScore(candidate) {
  const quality = candidate.qualityScore || 50;

  if (quality >= 80) return 30;
  if (quality >= 60) return 20;
  if (quality >= 40) return 10;
  return 5;
}

/**
 * Freshness score (0-20)
 */
function calculateFreshnessScore(candidate) {
  const lifespan = candidate.contentLifespan || 'evergreen';
  const updatedAt = candidate.updatedAt ? new Date(candidate.updatedAt) : null;

  if (!updatedAt) return 10;

  const daysSinceUpdate = (Date.now() - updatedAt.getTime()) / (1000 * 60 * 60 * 24);

  // Evergreen content doesn't decay as fast
  if (lifespan === 'evergreen') {
    if (daysSinceUpdate < 180) return 20;
    if (daysSinceUpdate < 365) return 15;
    return 10;
  }

  // Time-sensitive content decays faster
  if (lifespan === 'time-sensitive') {
    if (daysSinceUpdate < 30) return 20;
    if (daysSinceUpdate < 90) return 10;
    if (daysSinceUpdate < 180) return 5;
    return 0; // Stale
  }

  // Default/seasonal
  if (daysSinceUpdate < 90) return 15;
  if (daysSinceUpdate < 180) return 10;
  return 5;
}

/**
 * Link balance score - favor under-linked content (0-25)
 */
function calculateLinkBalanceScore(candidate) {
  const inboundCount = candidate.inboundLinkCount || 0;

  // Orphaned content gets boost
  if (inboundCount === 0) return 25;
  if (inboundCount < 3) return 20;
  if (inboundCount < 5) return 15;
  if (inboundCount < 10) return 10;
  if (inboundCount < 20) return 5;

  // Over-linked content gets small penalty
  return 0;
}

/**
 * Click depth score - favor deep/orphan pages (0-25)
 * Deep pages get higher scores to encourage linking to them
 */
function calculateClickDepthScore(candidate) {
  const depth = candidate.clickDepth;
  const inboundLinks = candidate.inboundLinkCount || 0;

  // If no click depth calculated, estimate from inbound links
  if (depth === undefined || depth === null) {
    if (inboundLinks === 0) return 25;  // Orphaned
    if (inboundLinks < 2) return 20;    // Likely deep
    if (inboundLinks < 5) return 10;
    return 5;
  }

  // Based on actual click depth
  if (depth >= 99) return 25;  // Orphaned/unreachable - highest priority
  if (depth >= 5) return 20;   // Very deep
  if (depth >= 4) return 15;   // Deep
  if (depth >= 3) return 10;   // Medium
  if (depth >= 2) return 5;    // Shallow
  return 0;                     // Homepage or 1 click away
}

/**
 * Check if candidate passes silo filter
 * Used for strict silo enforcement mode
 */
function passesSiloFilter(source, candidate, strict = false) {
  const sourceCluster = source.topicCluster;
  const candidateCluster = candidate.topicCluster;

  if (!sourceCluster || !candidateCluster) return true;

  // Same cluster always passes
  if (sourceCluster === candidateCluster) return true;

  // In strict mode, only same cluster allowed
  if (strict) return false;

  // In normal mode, related clusters also pass
  const sourceRelated = source.relatedClusters || [];
  const clusterRelations = CLUSTER_RELATIONSHIPS[sourceCluster] || [];

  return sourceRelated.includes(candidateCluster) ||
         clusterRelations.includes(candidateCluster);
}

/**
 * Apply scoring to a list of candidates
 */
function scoreAllCandidates(source, candidates, options = {}) {
  const scored = candidates.map(candidate => {
    const metadata = candidate.metadata || candidate;
    return calculateHybridScore(source, {
      ...metadata,
      score: candidate.score // Vector similarity from Pinecone
    }, options);
  });

  // Sort by total score descending
  return scored.sort((a, b) => b.totalScore - a.totalScore);
}

/**
 * Filter candidates based on minimum thresholds
 */
function filterCandidates(scoredCandidates, source, options = {}) {
  const {
    minScore = 40,
    minVectorScore = 30,
    maxResults = 10,
    requireSameCluster = false,
    strictSilo = false  // Only allow same cluster links
  } = options;

  let filtered = scoredCandidates
    .filter(c => c.totalScore >= minScore)
    .filter(c => c.vectorScore >= minVectorScore);

  // Apply silo filter if enabled
  if (strictSilo && source) {
    filtered = filtered.filter(c =>
      passesSiloFilter(source, c.candidate, true)
    );
  } else if (requireSameCluster) {
    filtered = filtered.filter(c => c.breakdown.cluster >= 40);
  }

  return filtered.slice(0, maxResults);
}

/**
 * Get linking recommendations
 */
function getRecommendations(source, candidates, options = {}) {
  const scored = scoreAllCandidates(source, candidates, options);
  const filtered = filterCandidates(scored, source, options);

  return {
    recommendations: filtered,
    totalCandidates: candidates.length,
    passedFilter: filtered.length,
    averageScore: filtered.length > 0
      ? Math.round(filtered.reduce((sum, c) => sum + c.totalScore, 0) / filtered.length)
      : 0
  };
}

module.exports = {
  calculateHybridScore,
  calculateClusterScore,
  calculateFunnelScore,
  calculatePersonaScore,
  calculateQualityScore,
  calculateFreshnessScore,
  calculateLinkBalanceScore,
  calculateClickDepthScore,
  passesSiloFilter,
  scoreAllCandidates,
  filterCandidates,
  getRecommendations,
  CLUSTER_RELATIONSHIPS,
  FUNNEL_FLOW,
  PERSONA_COMPATIBILITY
};
