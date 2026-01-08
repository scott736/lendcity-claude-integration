/**
 * Outbound Link Management
 *
 * Manages and monitors external links, tracks link health,
 * and suggests authoritative sources for citation.
 */

const { getClient } = require('./claude');

// Trusted domains for real estate/finance content
const TRUSTED_DOMAINS = {
  government: [
    'canada.ca',
    'cmhc-schl.gc.ca',
    'osfi-bsif.gc.ca',
    'cra-arc.gc.ca',
    'ontario.ca',
    'gov.bc.ca',
    'alberta.ca'
  ],
  financial: [
    'bankofcanada.ca',
    'investopedia.com',
    'moneysense.ca',
    'ratehub.ca'
  ],
  realEstate: [
    'crea.ca',
    'realtor.ca',
    'reco.on.ca',
    'cmls.ca'
  ],
  statistics: [
    'statcan.gc.ca',
    'stats.gov.on.ca'
  ]
};

// Link health statuses
const LINK_STATUS = {
  HEALTHY: 'healthy',
  BROKEN: 'broken',
  REDIRECTED: 'redirected',
  TIMEOUT: 'timeout',
  UNCHECKED: 'unchecked'
};

// In-memory link registry (use database in production)
const linkRegistry = new Map();

/**
 * Register an outbound link
 */
function registerOutboundLink(sourcePostId, link) {
  const key = `${sourcePostId}:${link.url}`;

  linkRegistry.set(key, {
    sourcePostId,
    url: link.url,
    anchorText: link.anchorText || '',
    domain: extractDomain(link.url),
    registeredAt: new Date().toISOString(),
    lastChecked: null,
    status: LINK_STATUS.UNCHECKED,
    trustScore: calculateDomainTrust(extractDomain(link.url))
  });

  return linkRegistry.get(key);
}

/**
 * Extract domain from URL
 */
function extractDomain(url) {
  try {
    const parsed = new URL(url);
    return parsed.hostname.replace('www.', '');
  } catch {
    return null;
  }
}

/**
 * Calculate trust score for a domain
 */
function calculateDomainTrust(domain) {
  if (!domain) return 0;

  // Government sources = highest trust
  if (TRUSTED_DOMAINS.government.some(d => domain.includes(d))) {
    return 100;
  }

  // Official real estate associations
  if (TRUSTED_DOMAINS.realEstate.some(d => domain.includes(d))) {
    return 90;
  }

  // Statistics/data sources
  if (TRUSTED_DOMAINS.statistics.some(d => domain.includes(d))) {
    return 90;
  }

  // Financial institutions
  if (TRUSTED_DOMAINS.financial.some(d => domain.includes(d))) {
    return 80;
  }

  // Educational institutions
  if (domain.endsWith('.edu') || domain.endsWith('.edu.ca')) {
    return 85;
  }

  // News organizations (moderate trust)
  const newsOrgs = ['cbc.ca', 'globalnews.ca', 'bnnbloomberg.ca', 'theglobeandmail.com'];
  if (newsOrgs.some(d => domain.includes(d))) {
    return 70;
  }

  // Default - unknown domain
  return 50;
}

/**
 * Check link health (simulated - real implementation would make HTTP requests)
 */
async function checkLinkHealth(url) {
  // In production, make HEAD request to check status
  // For now, return unchecked status
  return {
    url,
    status: LINK_STATUS.UNCHECKED,
    checkedAt: new Date().toISOString(),
    responseTime: null,
    finalUrl: url
  };
}

/**
 * Batch check all registered links
 */
async function checkAllLinks() {
  const results = {
    healthy: [],
    broken: [],
    redirected: [],
    unchecked: []
  };

  for (const [key, link] of linkRegistry) {
    const health = await checkLinkHealth(link.url);
    link.lastChecked = health.checkedAt;
    link.status = health.status;

    results[health.status === LINK_STATUS.HEALTHY ? 'healthy' :
            health.status === LINK_STATUS.BROKEN ? 'broken' :
            health.status === LINK_STATUS.REDIRECTED ? 'redirected' : 'unchecked']
      .push({ ...link, health });
  }

  return {
    ...results,
    summary: {
      total: linkRegistry.size,
      healthy: results.healthy.length,
      broken: results.broken.length,
      redirected: results.redirected.length,
      unchecked: results.unchecked.length
    }
  };
}

/**
 * Get outbound links for a specific article
 */
function getOutboundLinks(postId) {
  const links = [];

  for (const [key, link] of linkRegistry) {
    if (link.sourcePostId === postId) {
      links.push(link);
    }
  }

  return links;
}

/**
 * Analyze outbound link quality for an article
 */
function analyzeOutboundQuality(postId) {
  const links = getOutboundLinks(postId);

  if (links.length === 0) {
    return {
      postId,
      linkCount: 0,
      averageTrust: 0,
      score: 0,
      recommendation: 'Add authoritative outbound links to improve credibility'
    };
  }

  const averageTrust = links.reduce((sum, l) => sum + l.trustScore, 0) / links.length;
  const brokenCount = links.filter(l => l.status === LINK_STATUS.BROKEN).length;

  // Score based on link quality
  let score = Math.round(averageTrust);
  if (brokenCount > 0) {
    score -= brokenCount * 10;
  }

  // Optimal outbound link count is 2-5
  if (links.length >= 2 && links.length <= 5) {
    score += 10;
  } else if (links.length > 10) {
    score -= 10; // Too many outbound links
  }

  return {
    postId,
    linkCount: links.length,
    averageTrust: Math.round(averageTrust),
    brokenCount,
    score: Math.min(100, Math.max(0, score)),
    links: links.map(l => ({
      url: l.url,
      domain: l.domain,
      trustScore: l.trustScore,
      status: l.status
    })),
    recommendation: generateOutboundRecommendation(links, averageTrust)
  };
}

/**
 * Generate recommendation for outbound links
 */
function generateOutboundRecommendation(links, averageTrust) {
  if (links.length === 0) {
    return 'Add 2-5 authoritative outbound links to sources like CMHC, StatCan, or industry associations';
  }

  if (links.length > 10) {
    return 'Consider reducing outbound links - too many can dilute page authority';
  }

  if (averageTrust < 50) {
    return 'Replace low-trust outbound links with authoritative sources (government, industry associations)';
  }

  const brokenLinks = links.filter(l => l.status === LINK_STATUS.BROKEN);
  if (brokenLinks.length > 0) {
    return `Fix ${brokenLinks.length} broken outbound link(s) to maintain credibility`;
  }

  return 'Outbound link profile looks healthy';
}

/**
 * Suggest authoritative sources for a topic
 */
async function suggestAuthoritativeSources(topic, cluster = null) {
  const client = getClient();

  const prompt = `You are a content specialist for a Canadian real estate investment education website.

TOPIC: ${topic}
${cluster ? `CONTENT CLUSTER: ${cluster}` : ''}

Suggest 5 authoritative sources that should be cited when writing about this topic.

Focus on:
1. Canadian government sources (CMHC, CRA, provincial regulators)
2. Industry associations (CREA, RECO, etc.)
3. Statistical sources (StatCan)
4. Reputable financial institutions

For each source, provide:
- Name of the organization
- Specific page/resource URL if known (or domain)
- Why it's authoritative for this topic
- Suggested anchor text

Return JSON:
{
  "sources": [
    {
      "name": "Organization Name",
      "url": "https://...",
      "authority": "Why this source is authoritative",
      "suggestedAnchor": "example anchor text"
    }
  ]
}`;

  const response = await client.messages.create({
    model: 'claude-sonnet-4-20250514',
    max_tokens: 1000,
    messages: [{ role: 'user', content: prompt }]
  });

  try {
    const text = response.content[0].text.trim();
    const jsonMatch = text.match(/\{[\s\S]*\}/);
    if (jsonMatch) {
      const result = JSON.parse(jsonMatch[0]);
      // Add trust scores
      result.sources = result.sources.map(s => ({
        ...s,
        trustScore: calculateDomainTrust(extractDomain(s.url))
      }));
      return result;
    }
    return JSON.parse(text);
  } catch (e) {
    return {
      error: 'Failed to parse response',
      rawResponse: response.content[0].text
    };
  }
}

/**
 * Get domain statistics across all articles
 */
function getOutboundDomainStats() {
  const domainCounts = {};
  const domainTrust = {};

  for (const [, link] of linkRegistry) {
    const domain = link.domain;
    if (domain) {
      domainCounts[domain] = (domainCounts[domain] || 0) + 1;
      domainTrust[domain] = link.trustScore;
    }
  }

  // Sort by count
  const sorted = Object.entries(domainCounts)
    .sort(([, a], [, b]) => b - a)
    .map(([domain, count]) => ({
      domain,
      count,
      trustScore: domainTrust[domain]
    }));

  return {
    domains: sorted,
    totalLinks: linkRegistry.size,
    uniqueDomains: sorted.length,
    averageTrust: sorted.length > 0
      ? Math.round(sorted.reduce((sum, d) => sum + d.trustScore, 0) / sorted.length)
      : 0
  };
}

/**
 * Identify articles needing outbound links
 */
function findArticlesNeedingOutboundLinks(articles) {
  const needs = [];

  for (const article of articles) {
    const meta = article.metadata || article;
    const postId = meta.postId;
    const links = getOutboundLinks(postId);

    // Articles with no outbound links
    if (links.length === 0) {
      needs.push({
        postId,
        title: meta.title,
        cluster: meta.topicCluster,
        reason: 'No outbound links',
        priority: 'high'
      });
    }
    // Articles with only low-trust links
    else if (links.every(l => l.trustScore < 60)) {
      needs.push({
        postId,
        title: meta.title,
        cluster: meta.topicCluster,
        reason: 'Only low-trust outbound links',
        priority: 'medium'
      });
    }
  }

  return needs;
}

module.exports = {
  TRUSTED_DOMAINS,
  LINK_STATUS,
  registerOutboundLink,
  extractDomain,
  calculateDomainTrust,
  checkLinkHealth,
  checkAllLinks,
  getOutboundLinks,
  analyzeOutboundQuality,
  suggestAuthoritativeSources,
  getOutboundDomainStats,
  findArticlesNeedingOutboundLinks
};
