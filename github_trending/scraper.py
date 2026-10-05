import requests
from bs4 import BeautifulSoup
import time
import random
from database import insert_repos

def get_trending_repos():
    url = "https://github.com/trending"
    headers = {
        "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
    }
    
    print(f"Fetching {url}...")
    # Basic rate-limiting handling: sleep before request
    time.sleep(random.uniform(1, 3)) 
    
    response = requests.get(url, headers=headers)
    if response.status_code != 200:
        print(f"Failed to fetch data: HTTP {response.status_code}")
        return []

    soup = BeautifulSoup(response.text, 'html.parser')
    repo_articles = soup.find_all('article', class_='Box-row')
    
    if not repo_articles:
        print("⚠️  WARNING: Could not find any repositories matching <article class='Box-row'>.")
        print("GitHub might have changed its HTML structure, or we are being rate-limited.")
        print("Please check https://github.com/trending manually or inspect the HTML response.")
        return []

    repos = []
    for article in repo_articles[:10]: # Extract Top 10
        try:
            # Extract Repo Name
            h2 = article.find('h2', class_='h3 lh-condensed')
            a_tag = h2.find('a') if h2 else None
            repo_name = a_tag['href'].strip()[1:] if a_tag else "Unknown"
            
            # Extract Language
            lang_span = article.find('span', itemprop='programmingLanguage')
            language = lang_span.text.strip() if lang_span else "Unknown"
            
            # Extract Stars (Today's stars or total as fallback)
            stars_today_span = article.find('span', class_='float-sm-right')
            stars = 0
            if stars_today_span and 'stars today' in stars_today_span.text:
                stars_text = stars_today_span.text.strip().replace('stars today', '').replace(',', '').strip()
                try:
                    stars = int(stars_text)
                except ValueError:
                    pass
            else:
                # Fallback to total stars
                muted_links = article.find_all('a', class_='Link--muted')
                for link in muted_links:
                    if 'octicon-star' in str(link):
                        stars_text = link.text.strip().replace(',', '')
                        try:
                            stars = int(stars_text)
                            break
                        except ValueError:
                            pass
                            
            repos.append({
                'name': repo_name,
                'language': language,
                'stars': stars
            })
        except Exception as e:
            print(f"Error parsing an article: {e}")
            continue
            
    return repos

if __name__ == "__main__":
    print("Starting Data Collection (Agent 1)...")
    repos = get_trending_repos()
    if repos:
        print(f"Scraped {len(repos)} repositories successfully.")
        print("Passing data to Data Engineering (Agent 2)...")
        insert_repos(repos)
    else:
        print("No repositories found or scraping failed.")
