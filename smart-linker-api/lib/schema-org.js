/**
 * Schema.org Structured Data Generation
 *
 * Generates rich structured data for articles to improve
 * search appearance and enable rich results.
 */

const { getClient } = require('./claude');

/**
 * Generate Article schema
 */
function generateArticleSchema(article, options = {}) {
  const {
    organizationName = 'LendCity',
    organizationUrl = 'https://lendcity.ca',
    logoUrl = 'https://lendcity.ca/logo.png'
  } = options;

  const meta = article.metadata || article;

  return {
    '@context': 'https://schema.org',
    '@type': 'Article',
    'headline': meta.title,
    'description': meta.summary || meta.description || '',
    'image': meta.featuredImage || '',
    'datePublished': meta.publishedAt,
    'dateModified': meta.updatedAt || meta.publishedAt,
    'author': {
      '@type': 'Organization',
      'name': organizationName,
      'url': organizationUrl
    },
    'publisher': {
      '@type': 'Organization',
      'name': organizationName,
      'url': organizationUrl,
      'logo': {
        '@type': 'ImageObject',
        'url': logoUrl
      }
    },
    'mainEntityOfPage': {
      '@type': 'WebPage',
      '@id': meta.url
    },
    'articleSection': formatClusterName(meta.topicCluster),
    'keywords': [
      ...(meta.mainTopics || []),
      ...(meta.semanticKeywords || [])
    ].slice(0, 10).join(', ')
  };
}

/**
 * Generate HowTo schema for instructional content
 */
function generateHowToSchema(article, steps = []) {
  const meta = article.metadata || article;

  return {
    '@context': 'https://schema.org',
    '@type': 'HowTo',
    'name': meta.title,
    'description': meta.summary || '',
    'step': steps.map((step, i) => ({
      '@type': 'HowToStep',
      'position': i + 1,
      'name': step.title || `Step ${i + 1}`,
      'text': step.description || step.text
    }))
  };
}

/**
 * Generate FAQ schema
 */
function generateFAQSchema(questions) {
  return {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    'mainEntity': questions.map(q => ({
      '@type': 'Question',
      'name': q.question,
      'acceptedAnswer': {
        '@type': 'Answer',
        'text': q.answer
      }
    }))
  };
}

/**
 * Generate BreadcrumbList schema
 */
function generateBreadcrumbSchema(breadcrumbs, baseUrl = 'https://lendcity.ca') {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    'itemListElement': breadcrumbs.map((crumb, i) => ({
      '@type': 'ListItem',
      'position': i + 1,
      'name': crumb.name,
      'item': crumb.url.startsWith('http') ? crumb.url : `${baseUrl}${crumb.url}`
    }))
  };
}

/**
 * Generate WebPage schema with related links
 */
function generateWebPageSchema(article, relatedArticles = []) {
  const meta = article.metadata || article;

  const schema = {
    '@context': 'https://schema.org',
    '@type': 'WebPage',
    'name': meta.title,
    'description': meta.summary || '',
    'url': meta.url,
    'datePublished': meta.publishedAt,
    'dateModified': meta.updatedAt,
    'isPartOf': {
      '@type': 'WebSite',
      'name': 'LendCity',
      'url': 'https://lendcity.ca'
    }
  };

  if (relatedArticles.length > 0) {
    schema.relatedLink = relatedArticles.map(r => ({
      '@type': 'WebPage',
      'name': r.title,
      'url': r.url
    }));
  }

  return schema;
}

/**
 * Auto-detect and generate appropriate schema
 */
async function autoGenerateSchema(article, content) {
  const meta = article.metadata || article;
  const schemas = [];

  // Always add Article schema
  schemas.push(generateArticleSchema(article));

  // Detect if content is a how-to guide
  const isHowTo = meta.title?.toLowerCase().includes('how to') ||
                  content?.toLowerCase().includes('step 1') ||
                  content?.toLowerCase().includes('first step');

  if (isHowTo) {
    const steps = await extractStepsFromContent(content);
    if (steps.length > 0) {
      schemas.push(generateHowToSchema(article, steps));
    }
  }

  // Detect FAQ content
  const hasFAQ = content?.includes('?') &&
                 (content?.toLowerCase().includes('faq') ||
                  content?.toLowerCase().includes('frequently asked'));

  if (hasFAQ) {
    const questions = await extractQuestionsFromContent(content);
    if (questions.length > 0) {
      schemas.push(generateFAQSchema(questions));
    }
  }

  // Generate breadcrumbs
  const breadcrumbs = generateBreadcrumbsFromUrl(meta.url, meta.topicCluster);
  schemas.push(generateBreadcrumbSchema(breadcrumbs));

  return schemas;
}

/**
 * Extract steps from how-to content using Claude
 */
async function extractStepsFromContent(content) {
  const client = getClient();

  const response = await client.messages.create({
    model: 'claude-sonnet-4-20250514',
    max_tokens: 500,
    messages: [{
      role: 'user',
      content: `Extract the main steps from this how-to content. Return JSON array:
[{"title": "Step title", "description": "Step description"}]

Content:
${content.slice(0, 4000)}`
    }]
  });

  try {
    const text = response.content[0].text;
    const jsonMatch = text.match(/\[[\s\S]*\]/);
    return jsonMatch ? JSON.parse(jsonMatch[0]) : [];
  } catch (e) {
    return [];
  }
}

/**
 * Extract Q&A pairs from FAQ content
 */
async function extractQuestionsFromContent(content) {
  const client = getClient();

  const response = await client.messages.create({
    model: 'claude-sonnet-4-20250514',
    max_tokens: 500,
    messages: [{
      role: 'user',
      content: `Extract question-answer pairs from this FAQ content. Return JSON array:
[{"question": "...", "answer": "..."}]

Content:
${content.slice(0, 4000)}`
    }]
  });

  try {
    const text = response.content[0].text;
    const jsonMatch = text.match(/\[[\s\S]*\]/);
    return jsonMatch ? JSON.parse(jsonMatch[0]) : [];
  } catch (e) {
    return [];
  }
}

/**
 * Generate breadcrumbs from URL structure
 */
function generateBreadcrumbsFromUrl(url, cluster) {
  const breadcrumbs = [
    { name: 'Home', url: '/' }
  ];

  if (cluster) {
    breadcrumbs.push({
      name: formatClusterName(cluster),
      url: `/category/${cluster}`
    });
  }

  // Add current page
  const urlParts = url.split('/').filter(Boolean);
  if (urlParts.length > 0) {
    breadcrumbs.push({
      name: formatClusterName(urlParts[urlParts.length - 1]),
      url: url
    });
  }

  return breadcrumbs;
}

/**
 * Format cluster name for display
 */
function formatClusterName(cluster) {
  if (!cluster) return '';
  return cluster
    .split('-')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

/**
 * Validate schema against Schema.org requirements
 */
function validateSchema(schema) {
  const errors = [];

  if (!schema['@context']) {
    errors.push('Missing @context');
  }

  if (!schema['@type']) {
    errors.push('Missing @type');
  }

  // Type-specific validation
  if (schema['@type'] === 'Article') {
    if (!schema.headline) errors.push('Article missing headline');
    if (!schema.datePublished) errors.push('Article missing datePublished');
  }

  if (schema['@type'] === 'HowTo') {
    if (!schema.step || schema.step.length === 0) {
      errors.push('HowTo missing steps');
    }
  }

  return {
    valid: errors.length === 0,
    errors
  };
}

module.exports = {
  generateArticleSchema,
  generateHowToSchema,
  generateFAQSchema,
  generateBreadcrumbSchema,
  generateWebPageSchema,
  autoGenerateSchema,
  validateSchema,
  formatClusterName
};
