"""
API Bridge for PHP to Python ML Model
Handles JSON input/output for crop recommendation predictions
"""

import sys
import os
import json

# Add current directory to path for imports
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from crop_recommendation_ml import CropRecommendationML

def main():
    """Handle JSON input from PHP and return JSON predictions"""
    try:
        # Read JSON input from stdin
        input_data = json.load(sys.stdin)
        
        # Initialize ML model
        ml = CropRecommendationML()
        
        # Determine model path
        script_dir = os.path.dirname(os.path.abspath(__file__))
        model_path = os.path.join(script_dir, 'crop_ml_models.pkl')
        
        # Check if model file exists
        if not os.path.exists(model_path):
            response = {
                'success': False,
                'error': f'ML model file not found at {model_path}',
                'message': 'Please train the models first: python ml/crop_recommendation_ml.py train'
            }
            print(json.dumps(response))
            sys.exit(1)
        
        ml.load_models(model_path)
        
        # Extract features
        features = {
            'N': input_data.get('N', 0),
            'P': input_data.get('P', 0),
            'K': input_data.get('K', 0),
            'temperature': input_data.get('temperature', 0),
            'humidity': input_data.get('humidity', 0),
            'ph': input_data.get('ph', 0),
            'rainfall': input_data.get('rainfall', 0)
        }
        
        # Get model type (default: ensemble)
        model_type = input_data.get('model_type', 'ensemble')
        
        # Get predictions
        predictions = ml.predict(features, model_type=model_type)
        
        # Get feature importance if requested
        feature_importance = None
        if input_data.get('include_importance', False):
            feature_importance = ml.get_feature_importance('random_forest')
            feature_importance = [{'feature': f[0], 'importance': float(f[1])} 
                                 for f in feature_importance]
        
        # Prepare response
        response = {
            'success': True,
            'predictions': predictions,
            'top_recommendation': predictions[0] if predictions else None,
            'feature_importance': feature_importance
        }
        
        # Output JSON
        print(json.dumps(response))
        
    except FileNotFoundError:
        # Models not trained yet
        response = {
            'success': False,
            'error': 'ML models not trained. Please run training script first.',
            'message': 'Run: python ml/crop_recommendation_ml.py train'
        }
        print(json.dumps(response))
        sys.exit(1)
        
    except Exception as e:
        response = {
            'success': False,
            'error': str(e)
        }
        print(json.dumps(response))
        sys.exit(1)

if __name__ == '__main__':
    main()

