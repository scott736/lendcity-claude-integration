/**
 * Seasonal Link Boosting
 *
 * Automatically boost links to seasonally relevant content
 * based on time of year and market cycles.
 */

// Seasonal content calendar for Canadian real estate
const SEASONAL_CONTENT = {
  // Month-based seasons (1-12)
  1: { // January
    topics: ['tax-planning', 'year-end-review', 'market-forecast', 'rrsp-investing'],
    boost: 1.3,
    reason: 'New year planning and tax season prep'
  },
  2: { // February
    topics: ['rrsp-deadline', 'tax-strategies', 'winter-market', 'pre-approval'],
    boost: 1.4,
    reason: 'RRSP deadline approaching'
  },
  3: { // March
    topics: ['spring-market', 'rrsp-deadline', 'tax-filing', 'market-timing'],
    boost: 1.3,
    reason: 'RRSP deadline and spring market prep'
  },
  4: { // April
    topics: ['spring-market', 'tax-filing', 'first-time-buyer', 'mortgage-rates'],
    boost: 1.2,
    reason: 'Spring buying season begins'
  },
  5: { // May
    topics: ['spring-market', 'home-inspection', 'bidding-wars', 'first-time-buyer'],
    boost: 1.4,
    reason: 'Peak spring market'
  },
  6: { // June
    topics: ['summer-market', 'moving', 'renovation', 'rental-properties'],
    boost: 1.2,
    reason: 'Summer transition'
  },
  7: { // July
    topics: ['summer-market', 'vacation-property', 'rental-income', 'property-management'],
    boost: 1.1,
    reason: 'Summer slowdown, rental focus'
  },
  8: { // August
    topics: ['back-to-school', 'fall-prep', 'rental-properties', 'student-housing'],
    boost: 1.2,
    reason: 'Back to school, rental season'
  },
  9: { // September
    topics: ['fall-market', 'mortgage-renewal', 'refinancing', 'investment-strategy'],
    boost: 1.3,
    reason: 'Fall market pickup'
  },
  10: { // October
    topics: ['fall-market', 'year-end-planning', 'tax-strategies', 'mortgage-rates'],
    boost: 1.2,
    reason: 'Active fall market'
  },
  11: { // November
    topics: ['year-end-planning', 'tax-loss-selling', 'winter-prep', 'market-slowdown'],
    boost: 1.1,
    reason: 'Year-end preparation'
  },
  12: { // December
    topics: ['year-end-review', 'tax-planning', 'market-forecast', 'goal-setting'],
    boost: 1.2,
    reason: 'Year-end reflection and planning'
  }
};

// Market cycle phases
const MARKET_CYCLES = {
  rising: {
    topics: ['buying-strategy', 'appreciation', 'equity', 'timing'],
    boost: 1.2
  },
  peak: {
    topics: ['selling', 'equity-takeout', 'refinancing', 'caution'],
    boost: 1.3
  },
  declining: {
    topics: ['buying-opportunity', 'cash-reserves', 'negotiation', 'patience'],
    boost: 1.2
  },
  bottom: {
    topics: ['buying-opportunity', 'value-investing', 'brrrr-strategy', 'cash-flow'],
    boost: 1.4
  }
};

/**
 * Get current seasonal boost factors
 */
function getCurrentSeasonalBoosts() {
  const now = new Date();
  const month = now.getMonth() + 1; // 1-12

  const seasonal = SEASONAL_CONTENT[month] || { topics: [], boost: 1.0 };

  // Look ahead to next month for upcoming topics
  const nextMonth = month === 12 ? 1 : month + 1;
  const upcoming = SEASONAL_CONTENT[nextMonth] || { topics: [] };

  return {
    currentMonth: month,
    currentTopics: seasonal.topics,
    currentBoost: seasonal.boost,
    reason: seasonal.reason,
    upcomingTopics: upcoming.topics,
    daysUntilNextSeason: getDaysUntilNextMonth()
  };
}

/**
 * Calculate days until next month
 */
function getDaysUntilNextMonth() {
  const now = new Date();
  const nextMonth = new Date(now.getFullYear(), now.getMonth() + 1, 1);
  return Math.ceil((nextMonth - now) / (1000 * 60 * 60 * 24));
}

/**
 * Calculate seasonal score for an article
 */
function calculateSeasonalScore(article) {
  const meta = article.metadata || article;
  const cluster = meta.topicCluster || '';
  const keywords = [...(meta.mainTopics || []), ...(meta.semanticKeywords || [])];
  const allTopics = [cluster, ...keywords].map(t => t.toLowerCase());

  const seasonal = getCurrentSeasonalBoosts();

  // Check if article matches current seasonal topics
  let matchScore = 0;
  for (const topic of seasonal.currentTopics) {
    if (allTopics.some(t => t.includes(topic) || topic.includes(t))) {
      matchScore += 10;
    }
  }

  // Check upcoming topics (smaller boost)
  for (const topic of seasonal.upcomingTopics) {
    if (allTopics.some(t => t.includes(topic) || topic.includes(t))) {
      matchScore += 5;
    }
  }

  return {
    score: Math.min(matchScore, 30), // Cap at 30 points
    isSeasonallyRelevant: matchScore > 0,
    matchedTopics: seasonal.currentTopics.filter(t =>
      allTopics.some(at => at.includes(t) || t.includes(at))
    ),
    boostMultiplier: matchScore > 0 ? seasonal.currentBoost : 1.0
  };
}

/**
 * Get seasonally boosted link recommendations
 */
function applySeasonalBoosting(candidates) {
  return candidates.map(candidate => {
    const seasonalData = calculateSeasonalScore(candidate);
    const originalScore = candidate.score || candidate.totalScore || 0;

    return {
      ...candidate,
      seasonalScore: seasonalData.score,
      seasonalBoost: seasonalData.boostMultiplier,
      isSeasonallyRelevant: seasonalData.isSeasonallyRelevant,
      boostedScore: originalScore * seasonalData.boostMultiplier,
      matchedSeasonalTopics: seasonalData.matchedTopics
    };
  }).sort((a, b) => b.boostedScore - a.boostedScore);
}

/**
 * Get content suggestions for upcoming seasons
 */
function getUpcomingSeasonalSuggestions() {
  const suggestions = [];
  const now = new Date();
  const currentMonth = now.getMonth() + 1;

  // Look at next 3 months
  for (let i = 1; i <= 3; i++) {
    const targetMonth = ((currentMonth - 1 + i) % 12) + 1;
    const seasonal = SEASONAL_CONTENT[targetMonth];

    if (seasonal) {
      suggestions.push({
        month: targetMonth,
        monthName: getMonthName(targetMonth),
        daysUntil: getDaysUntilMonth(targetMonth),
        topics: seasonal.topics,
        reason: seasonal.reason,
        contentSuggestions: seasonal.topics.map(topic => ({
          topic,
          title: generateSeasonalTitle(topic, targetMonth)
        }))
      });
    }
  }

  return suggestions;
}

/**
 * Get month name
 */
function getMonthName(month) {
  const months = ['', 'January', 'February', 'March', 'April', 'May', 'June',
                  'July', 'August', 'September', 'October', 'November', 'December'];
  return months[month];
}

/**
 * Get days until a specific month
 */
function getDaysUntilMonth(targetMonth) {
  const now = new Date();
  const currentMonth = now.getMonth() + 1;

  if (targetMonth <= currentMonth) {
    // Next year
    const target = new Date(now.getFullYear() + 1, targetMonth - 1, 1);
    return Math.ceil((target - now) / (1000 * 60 * 60 * 24));
  }

  const target = new Date(now.getFullYear(), targetMonth - 1, 1);
  return Math.ceil((target - now) / (1000 * 60 * 60 * 24));
}

/**
 * Generate seasonal title suggestion
 */
function generateSeasonalTitle(topic, month) {
  const year = new Date().getFullYear();
  const monthName = getMonthName(month);

  const templates = {
    'tax-planning': `${year} Canadian Real Estate Tax Planning Guide`,
    'rrsp-deadline': `RRSP Deadline ${year}: Real Estate Investment Strategies`,
    'spring-market': `${monthName} ${year} Real Estate Market: What Investors Need to Know`,
    'first-time-buyer': `First-Time Home Buyer Guide for ${monthName} ${year}`,
    'mortgage-rates': `Mortgage Rate Update: ${monthName} ${year} Forecast`,
    'rental-properties': `Rental Property Investing in ${monthName} ${year}`,
    'year-end-planning': `Year-End Real Estate Investment Checklist ${year}`
  };

  return templates[topic] || `${topic.replace(/-/g, ' ').replace(/\b\w/g, l => l.toUpperCase())} - ${monthName} ${year}`;
}

/**
 * Check if article needs seasonal update
 */
function needsSeasonalUpdate(article) {
  const meta = article.metadata || article;
  const updatedAt = meta.updatedAt ? new Date(meta.updatedAt) : null;
  const lifespan = meta.contentLifespan;

  if (!updatedAt) return true;

  // Seasonal content should be updated annually
  if (lifespan === 'seasonal') {
    const daysSinceUpdate = (Date.now() - updatedAt.getTime()) / (1000 * 60 * 60 * 24);
    return daysSinceUpdate > 300; // Update if older than ~10 months
  }

  // Time-sensitive content should be updated more frequently
  if (lifespan === 'time-sensitive') {
    const daysSinceUpdate = (Date.now() - updatedAt.getTime()) / (1000 * 60 * 60 * 24);
    return daysSinceUpdate > 60;
  }

  return false;
}

module.exports = {
  SEASONAL_CONTENT,
  MARKET_CYCLES,
  getCurrentSeasonalBoosts,
  calculateSeasonalScore,
  applySeasonalBoosting,
  getUpcomingSeasonalSuggestions,
  needsSeasonalUpdate
};
