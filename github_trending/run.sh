#!/bin/bash

# Exit on any error
set -e

echo "🚀 Starting GitHub Trending Pipeline Orchestration..."

# 1. Virtual Environment Setup
if [ ! -d "venv" ]; then
    echo "📦 Creating virtual environment..."
    python3 -m venv venv
fi

echo "🔄 Activating virtual environment..."
source venv/bin/activate

echo "📥 Installing dependencies..."
pip install -r requirements.txt

# 2. Database Initialization (Agent 2)
echo "🗄️  Running Data Engineering (Database Setup)..."
python database.py

# 3. Data Collection (Agent 1)
echo "🕸️  Running Data Collection (Scraper)..."
python scraper.py

# 4. Frontend Visualization (Agent 3)
echo "📊 Starting Frontend (Streamlit Dashboard)..."
echo "The dashboard will automatically open in your default browser."
streamlit run dashboard.py
